<?php

namespace Gadya\Cms\Quality;

use Gadya\Cms\Models\Fix;
use Gadya\Cms\Models\PageScore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The site's accessibility work, written down: what was checked, when,
 * what was found, what was put right and what is still outstanding.
 *
 * This is deliberately not a badge or an overlay. It claims only what the
 * record supports - automated checks, dated, with a remediation history -
 * because a claim of full conformance nobody tested is worse than no
 * claim at all, and is itself what gets businesses into trouble.
 */
class AccessibilityRecord
{
    /** What the site aims at. Stated, not claimed as achieved. */
    public const TARGET = 'WCAG 2.2 level AA';

    public function __construct(private readonly Failures $failures) {}

    /** Whether anything has been checked at all. */
    public function exists(): bool
    {
        return PageScore::query()->whereNotNull('accessibility')->exists();
    }

    /** When the first check ran, which is how far the record goes back. */
    public function since(): ?Carbon
    {
        return PageScore::query()->min('checked_at') === null
            ? null
            : Carbon::parse(PageScore::query()->min('checked_at'));
    }

    public function lastCheckedAt(): ?Carbon
    {
        $latest = PageScore::query()->max('checked_at');

        return $latest === null ? null : Carbon::parse($latest);
    }

    /** The average accessibility score of the pages last checked, 0-100. */
    public function score(): ?int
    {
        $scores = $this->latestPerPath()->pluck('accessibility')->filter(fn ($score): bool => $score !== null);

        return $scores->isEmpty() ? null : (int) round($scores->average());
    }

    /** How many pages the record covers. */
    public function pagesChecked(): int
    {
        return $this->latestPerPath()->count();
    }

    /**
     * What is still wrong, in the words the client and her lawyer both
     * need: the barrier, where it is, and whether it is ours or hers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstanding(): Collection
    {
        return $this->failures->all()->where('category', 'accessibility')->values();
    }

    /**
     * Everything put right, newest first: the fixes the CMS made, and the
     * barriers that were in an earlier check and are gone from the latest.
     *
     * @return Collection<int, array{what: string, where: string, on: Carbon|null, by: string}>
     */
    public function remediated(): Collection
    {
        return collect($this->fixesMade())
            ->merge($this->barriersGone())
            ->sortByDesc(fn (array $entry): int => $entry['on']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The statement itself, ready to render: every claim traceable to
     * something in this record.
     *
     * @return array<string, mixed>
     */
    public function statement(): array
    {
        $outstanding = $this->outstanding();

        return [
            'business' => (string) (config('gadya-cms.seo.site_name') ?: config('app.name')),
            'target' => self::TARGET,
            /*
             * "Partially conformant" is the honest answer whenever anything
             * is outstanding, and automated checks alone never justify more
             * than that, so a clean run says so rather than claiming it all.
             */
            'conformance' => $outstanding->isEmpty()
                ? 'Partially conformant. Automated checks of '.$this->pagesChecked().' pages find no failures, and parts of '.self::TARGET.' can only be judged by a person.'
                : 'Partially conformant. '.$outstanding->count().' known issues are listed below, with what is being done about them.',
            'assessed' => 'Automated checks run against the live site by Google Lighthouse, weekly, page by page. Issues in the words and photographs are corrected in the content management system; issues in the code are corrected by Gadya Media.',
            'checked_pages' => $this->pagesChecked(),
            'score' => $this->score(),
            'last_checked_at' => $this->lastCheckedAt(),
            'since' => $this->since(),
            'outstanding' => $outstanding,
            'remediated' => $this->remediated(),
            'contact' => array_filter([
                'email' => config('gadya-cms.seo.organization.email'),
                'telephone' => config('gadya-cms.seo.organization.telephone'),
            ]),
        ];
    }

    /**
     * @return Collection<int, array{what: string, where: string, on: Carbon|null, by: string}>
     */
    private function fixesMade(): Collection
    {
        return Fix::query()
            ->whereIn('audit', ['image-alt', 'input-image-alt', 'link-name', 'document-title', 'heading-order'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Fix $fix): array => [
                'what' => $fix->says(),
                'where' => (string) $fix->subject,
                'on' => $fix->created_at,
                'by' => $fix->written_by === 'gadya' ? 'Gadya Media' : 'the site',
            ]);
    }

    /**
     * Barriers an earlier check found and the latest one does not. Their
     * date is the check that no longer saw them - the day it was fixed,
     * as closely as the record can honestly put it.
     *
     * @return Collection<int, array{what: string, where: string, on: Carbon|null, by: string}>
     */
    private function barriersGone(): Collection
    {
        $latest = $this->latestPerPath();
        $gone = collect();

        foreach ($latest as $current) {
            $standing = collect((array) $current->failures)
                ->where('category', 'accessibility')
                ->pluck('id')
                ->all();

            $earlier = PageScore::query()
                ->where('path', $current->path)
                ->where('strategy', $current->strategy)
                ->where('id', '!=', $current->id)
                ->orderByDesc('checked_at')
                ->limit(6)
                ->get();

            foreach ($earlier as $older) {
                foreach ((array) $older->failures as $failure) {
                    if (($failure['category'] ?? '') !== 'accessibility') {
                        continue;
                    }

                    if (in_array($failure['id'] ?? '', $standing, true)) {
                        continue;
                    }

                    $gone->put((string) $current->path.'|'.($failure['id'] ?? ''), [
                        'what' => (string) ($failure['title'] ?? $failure['id'] ?? ''),
                        'where' => (string) $current->path,
                        'on' => $current->checked_at,
                        'by' => 'Gadya Media',
                    ]);
                }
            }
        }

        return $gone->values();
    }

    /**
     * @return Collection<int, PageScore>
     */
    private function latestPerPath(): Collection
    {
        return PageScore::query()
            ->orderByDesc('checked_at')
            ->get()
            ->unique(fn (PageScore $score): string => $score->path.'|'.$score->strategy)
            ->values();
    }
}
