<?php

namespace Gadya\Cms\Privacy;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Which privacy policy a visitor agreed to when they ticked a consent box.
 *
 * "They agreed to our privacy policy" is only worth something if it says
 * which one: policies change, and a complaint about last year's enquiry is
 * answered with last year's wording. The version is, in order:
 *
 * - `privacy.policy_version` in config, for a site that numbers its policy;
 * - when the policy lives on a CMS page, the moment the page's current
 *   wording went live - the oldest published revision, going back from the
 *   latest, in which the page reads as it does now (or the page's last
 *   change, when the revisions do not go back that far);
 * - otherwise nothing: a policy the CMS does not hold, it cannot date.
 */
class PolicyVersion
{
    /** How far back through the published revisions to look. */
    private const REVISIONS = 60;

    public function __construct(
        private readonly Consent $consent,
        private readonly SiteContentRepository $repository,
        private readonly SiteContext $siteContext,
    ) {}

    /**
     * @return array{url: string|null, version: string|null}
     */
    public function current(): array
    {
        return once(function (): array {
            $url = rescue(fn (): ?string => $this->consent->published()['policy_url'], null, report: false);
            $configured = config('gadya-cms.privacy.policy_version');

            if (is_scalar($configured) && trim((string) $configured) !== '') {
                return ['url' => $url, 'version' => trim((string) $configured)];
            }

            return ['url' => $url, 'version' => $url === null ? null : rescue(fn (): ?string => $this->pageVersion($url), null, report: false)];
        });
    }

    private function pageVersion(string $url): ?string
    {
        $document = $this->repository->published();
        $slug = $this->slugAt($url, $document);

        if ($slug === null) {
            return null;
        }

        $page = $document['pages'][$slug];
        $hash = sha1((string) json_encode($page));

        /*
         * The answer only changes when the page does, so it is kept under
         * the page's own fingerprint and worked out once.
         */
        return Cache::rememberForever('gadya-cms.policy-version.'.$this->siteContext->id().'.'.$hash, function () use ($slug, $page): ?string {
            $since = null;

            $revisions = Revision::query()
                ->where('site_id', $this->siteContext->id())
                ->latest('published_at')
                ->latest('id')
                ->limit(self::REVISIONS)
                ->get(['id', 'snapshot', 'published_at']);

            foreach ($revisions as $revision) {
                if (($revision->snapshot['pages'][$slug] ?? null) != $page) {
                    break;
                }

                $since = $revision->published_at;
            }

            $since ??= Page::query()->where('site_id', $this->siteContext->id())->where('slug', $slug)->value('updated_at');

            return $since === null ? null : Carbon::parse($since)->toIso8601String();
        });
    }

    /**
     * The CMS page a policy address points at, if it is one of ours.
     *
     * @param  array<string, mixed>  $document
     */
    private function slugAt(string $url, array $document): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '' && $host !== parse_url((string) url('/'), PHP_URL_HOST)) {
            return null;
        }

        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        $registry = app(PageRegistry::class);

        foreach (array_keys((array) ($document['pages'] ?? [])) as $slug) {
            if (is_array($document['pages'][$slug]) && '/'.trim($registry->publicPathFor((string) $slug, $document), '/') === $path) {
                return (string) $slug;
            }
        }

        return null;
    }
}
