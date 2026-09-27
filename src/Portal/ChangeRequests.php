<?php

namespace Gadya\Cms\Portal;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Contracts\ResolvesPagePaths;
use Gadya\Cms\Editor\PreviewLink;
use Gadya\Cms\Filament\Resources\ChangeRequests\ChangeRequestResource;
use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Models\ChangeRequest;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Please change X on the website", asked in the portal and drafted here.
 *
 * The request arrives as a `content.request` command. The site's AI reads
 * the page and proposes new wording for the fields that need it; the
 * wording goes into the draft - the same draft the client edits by hand -
 * and never onto the live site. Whoever looks after the site is told, with
 * a preview link, and decides: publish it, change it, or discard it. The
 * portal hears which through the check-in.
 */
class ChangeRequests
{
    /** Longest before/after the portal is sent; it only needs a glimpse. */
    private const GLIMPSE = 500;

    /** Longest single field the AI is shown. */
    private const FIELD_LIMIT = 1500;

    /** Settled requests stay in the check-in this long, so the portal hears. */
    public const REPORT_DAYS = 7;

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly EditableFields $fields,
        private readonly ResolvesPagePaths $paths,
        private readonly PreviewLink $previews,
        private readonly ChangeProposer $proposer,
        private readonly SiteContext $siteContext,
    ) {}

    /**
     * Draft the change, and say what was done in the portal's shape.
     *
     * @param  array{request_id?: mixed, instructions?: mixed, page_url?: mixed, requested_by?: mixed}  $payload
     * @return array<string, mixed>
     */
    public function draft(array $payload): array
    {
        $portalId = (int) ($payload['request_id'] ?? 0);
        $instructions = trim((string) ($payload['instructions'] ?? ''));
        $requestedBy = Str::limit(trim((string) ($payload['requested_by'] ?? '')), 120, '') ?: null;

        if ($portalId < 1 || $instructions === '') {
            throw new RuntimeException('The request had no instructions to follow.');
        }

        /*
         * The same request delivered twice - a retry after a timeout - is
         * answered from what was already drafted rather than asking the AI
         * again and writing over whatever the editor has done since.
         */
        $existing = ChangeRequest::query()
            ->where('site_id', $this->siteContext->id())
            ->where('portal_id', $portalId)
            ->first();

        if ($existing !== null) {
            return $this->result($existing);
        }

        $document = $this->repository->draft();
        $candidates = $this->candidates($document, is_string($payload['page_url'] ?? null) ? $payload['page_url'] : null);

        $proposal = $this->proposer->propose($this->prompt($instructions, $requestedBy, $candidates));

        $slug = array_key_exists($proposal['page'], $candidates) ? $proposal['page'] : (count($candidates) === 1 ? array_key_first($candidates) : null);

        if ($slug === null) {
            throw new RuntimeException($proposal['reason'] !== '' ? $proposal['reason'] : 'The AI could not tell which page the request is about. Please say which page, or give its address.');
        }

        $changes = $this->applicable($candidates[$slug]['fields'], $proposal['changes']);

        if ($changes === []) {
            throw new RuntimeException($proposal['reason'] !== '' ? $proposal['reason'] : 'Nothing on the page could be changed to do that. It may need a new page, a photo or a developer.');
        }

        foreach ($changes as $change) {
            Arr::set($document, 'pages.'.$slug.'.'.$change['field'], $change['after']);
        }

        $this->repository->saveDraft($document);

        $page = Page::query()->where('site_id', $this->siteContext->id())->where('slug', $slug)->first();

        $request = ChangeRequest::query()->create([
            'site_id' => $this->siteContext->id(),
            'portal_id' => $portalId,
            'page_id' => $page?->getKey(),
            'page_slug' => $slug,
            'page_title' => Str::limit($candidates[$slug]['title'], 250, ''),
            'instructions' => $instructions,
            'requested_by' => $requestedBy,
            'status' => ChangeRequest::STATUS_DRAFTED,
            'changes' => $changes,
            'preview_url' => $this->previews->for($candidates[$slug]['path']),
        ]);

        $this->notifyEditors($request);

        return $this->result($request);
    }

    /**
     * After a publish: each drafted request either went live with it, or
     * was undone by hand beforehand, and the published page says which.
     */
    public function settleAfterPublish(Revision $revision): void
    {
        $requests = ChangeRequest::query()->drafted()->where('site_id', $revision->site_id)->get();

        foreach ($requests as $request) {
            $page = Page::query()->where('site_id', $request->site_id)->where('slug', $request->page_slug)->first();
            $published = is_array($page?->published) ? $page->published : [];

            $live = collect($request->changes ?? [])
                ->contains(fn (array $change): bool => Arr::get($published, $change['field']) === $change['after']);

            $request->update(['status' => $live ? ChangeRequest::STATUS_PUBLISHED : ChangeRequest::STATUS_DISCARDED]);
        }
    }

    /**
     * Throw the proposal away: each field goes back to what it said
     * before, unless someone has written something else there since, in
     * which case theirs is kept.
     */
    public function discard(ChangeRequest $request): void
    {
        if (! $request->isDrafted()) {
            return;
        }

        $document = $this->repository->draft();
        $restored = false;

        foreach ($request->changes ?? [] as $change) {
            $path = 'pages.'.$request->page_slug.'.'.$change['field'];

            if (Arr::get($document, $path) === $change['after']) {
                Arr::set($document, $path, $change['before']);
                $restored = true;
            }
        }

        if ($restored) {
            $this->repository->saveDraft($document);
        }

        $request->update(['status' => ChangeRequest::STATUS_DISCARDED]);
    }

    /**
     * What the check-in carries: every request still waiting, and those
     * settled lately, so the portal can close its own copy.
     *
     * @return list<array{id: int, status: string, page_url: string|null, updated_at: string|null}>
     */
    public function summary(): array
    {
        return ChangeRequest::query()
            ->where('site_id', $this->siteContext->id())
            ->where(fn ($query) => $query->drafted()->orWhere('updated_at', '>=', now()->subDays(self::REPORT_DAYS)))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (ChangeRequest $request): array => [
                'id' => $request->portal_id,
                'status' => $request->status,
                'page_url' => $this->pageUrl($request->page_slug),
                'updated_at' => $request->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function result(ChangeRequest $request): array
    {
        return [
            'status' => $request->status,
            'page_title' => $request->page_title,
            'page_url' => $this->pageUrl($request->page_slug),
            'preview_url' => $request->preview_url,
            'edit_url' => $this->editUrl($request),
            'changes' => collect($request->changes ?? [])
                ->map(fn (array $change): array => [
                    'field' => $change['field'],
                    'before' => $change['before'] === null ? null : Str::limit($change['before'], self::GLIMPSE),
                    'after' => Str::limit($change['after'], self::GLIMPSE),
                ])
                ->all(),
        ];
    }

    /**
     * The pages the request could be about, each with the text fields the
     * editor could change: the one at the address given, or every page
     * that is not archived.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array{title: string, path: string, fields: array<string, string>}>
     */
    private function candidates(array $document, ?string $pageUrl): array
    {
        $pages = collect(is_array($document['pages'] ?? null) ? $document['pages'] : [])
            ->filter(fn ($page): bool => is_array($page) && ($page['status'] ?? Page::STATUS_PUBLISHED) !== Page::STATUS_ARCHIVED);

        $candidates = [];

        foreach ($pages as $slug => $page) {
            $candidates[(string) $slug] = [
                'title' => (string) ($page['title'] ?? Str::headline((string) $slug)),
                'path' => $this->paths->publicPathFor((string) $slug, $document),
                'fields' => $this->textFields((string) $slug, $page),
            ];
        }

        if ($pageUrl !== null && trim($pageUrl) !== '') {
            $path = '/'.trim((string) (parse_url($pageUrl, PHP_URL_PATH) ?? ''), '/');
            $match = collect($candidates)->filter(fn (array $candidate): bool => rtrim($candidate['path'], '/') === rtrim($path, '/'));

            if ($match->isEmpty()) {
                throw new RuntimeException('There is no page at '.$path.' on this site that the editor can change.');
            }

            return $match->all();
        }

        return $candidates;
    }

    /**
     * Every field of a page the live editor may write that holds words -
     * photos are left to people.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, string>
     */
    private function textFields(string $slug, array $page): array
    {
        $fields = [];

        foreach (Arr::dot($page) as $field => $value) {
            $type = $this->fields->typeFor('pages.'.$slug.'.'.$field);

            if (is_string($value) && $type !== null && $type !== 'image') {
                $fields[(string) $field] = $value;
            }
        }

        return $fields;
    }

    /**
     * The proposal, cut down to fields that exist, may be written, and
     * actually change.
     *
     * @param  array<string, string>  $fields
     * @param  list<array{field: string, value: string}>  $proposed
     * @return list<array{field: string, before: string|null, after: string}>
     */
    private function applicable(array $fields, array $proposed): array
    {
        $changes = [];

        foreach ($proposed as $change) {
            $after = trim($change['value']);

            if (! array_key_exists($change['field'], $fields) || $after === '' || $after === $fields[$change['field']]) {
                continue;
            }

            $changes[$change['field']] = ['field' => $change['field'], 'before' => $fields[$change['field']], 'after' => $after];
        }

        return array_values($changes);
    }

    /**
     * @param  array<string, array{title: string, path: string, fields: array<string, string>}>  $candidates
     */
    private function prompt(string $instructions, ?string $requestedBy, array $candidates): string
    {
        $pages = collect($candidates)
            ->filter(fn (array $candidate): bool => $candidate['fields'] !== [])
            ->map(function (array $candidate, string $slug): string {
                $fields = collect($candidate['fields'])
                    ->map(fn (string $value, string $field): string => $field.': '.Str::limit(str_replace("\n", ' / ', $value), self::FIELD_LIMIT))
                    ->implode("\n");

                return "Page \"{$slug}\" - {$candidate['title']} ({$candidate['path']})\n{$fields}";
            })
            ->implode("\n\n");

        return 'The request'.($requestedBy === null ? '' : ', from '.$requestedBy).":\n{$instructions}\n\nThe pages and the text you may change:\n\n".Str::limit($pages, 24000);
    }

    /**
     * Everyone who may edit the site's pages hears about it in the panel,
     * where the preview and the discard button are.
     */
    private function notifyEditors(ChangeRequest $request): void
    {
        rescue(function () use ($request): void {
            if (! Schema::hasTable('notifications')) {
                return;
            }

            $model = config('auth.providers.users.model');

            if (! is_string($model) || ! class_exists($model)) {
                return;
            }

            $editors = $model::query()->get()->filter(
                fn (Model $user): bool => method_exists($user, 'notify') && $user->can(Abilities::gate(Abilities::CONTENT)),
            );

            if ($editors->isEmpty()) {
                return;
            }

            Notification::make()
                ->title('A change is ready for you to check')
                ->body(($request->requested_by ?? 'Someone').' asked: “'.Str::limit($request->instructions, 160).'” It is drafted on '.$request->page_title.'. Nothing is live until you publish.')
                ->icon('heroicon-o-sparkles')
                ->actions([
                    Action::make('preview')->label('Preview')->url($request->preview_url, shouldOpenInNewTab: true),
                    Action::make('review')->label('Review')->url($this->reviewUrl()),
                ])
                ->sendToDatabase($editors);
        }, report: true);
    }

    private function pageUrl(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        return url($this->paths->publicPathFor($slug, $this->repository->draft()));
    }

    private function editUrl(ChangeRequest $request): ?string
    {
        if ($request->page_id === null) {
            return null;
        }

        return rescue(
            fn (): string => PageResource::getUrl('edit', ['record' => $request->page_id], panel: (string) config('gadya-cms.panel', 'admin')),
            null,
            report: false,
        );
    }

    private function reviewUrl(): ?string
    {
        return rescue(
            fn (): string => ChangeRequestResource::getUrl(panel: (string) config('gadya-cms.panel', 'admin')),
            null,
            report: false,
        );
    }
}
