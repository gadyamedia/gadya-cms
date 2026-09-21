<?php

namespace Gadya\Cms\Quality;

use Gadya\Cms\Models\PageScore;
use Illuminate\Support\Collection;

/**
 * What Lighthouse found wrong with the site, sorted into the two piles
 * that matter to the person reading it: things the CMS can put right
 * itself, because they are words or photos she owns, and things that live
 * in the templates and need a developer - or Gadya - to change the code.
 */
class Failures
{
    /**
     * Audits the CMS can fix from the panel, and what it would change.
     * Everything else is code.
     */
    public const FIXABLE = [
        'image-alt' => 'A photo with no description of what it shows.',
        'input-image-alt' => 'A photo with no description of what it shows.',
        'meta-description' => 'A page with nothing to show under its name in search results.',
        'document-title' => 'A page with no title.',
        'link-name' => 'A link whose words do not say where it goes.',
        'image-size-responsive' => 'A photo served much larger than it is shown.',
        'heading-order' => 'Headings that jump a level, which a screen reader reads as a missing section.',
    ];

    /**
     * The latest run's failures for the whole site, worst first, with the
     * page each was found on.
     *
     * @return Collection<int, array{id: string, title: string, description: string, category: string, path: string, elements: list<array<string, string>>, fixable: bool, what: string|null}>
     */
    public function all(): Collection
    {
        return $this->latestScores()
            ->flatMap(fn (PageScore $score): array => collect((array) $score->failures)
                ->map(fn (array $failure): array => [
                    ...$failure,
                    'path' => (string) $score->path,
                    'fixable' => $this->isFixable((string) $failure['id']),
                    'what' => self::FIXABLE[(string) $failure['id']] ?? null,
                ])
                ->all())
            ->sortBy([
                fn (array $a, array $b): int => ($b['fixable'] <=> $a['fixable']),
                fn (array $a, array $b): int => $this->rank($a['category']) <=> $this->rank($b['category']),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function fixable(): Collection
    {
        return $this->all()->where('fixable', true)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forDevelopers(): Collection
    {
        return $this->all()->where('fixable', false)->values();
    }

    public function isFixable(string $audit): bool
    {
        return array_key_exists($audit, self::FIXABLE);
    }

    /**
     * A brief a developer - or an agent working on the templates - can act
     * on without opening Lighthouse: what is wrong, where, and on which
     * elements.
     *
     * @param  array<string, mixed>  $failure
     */
    public function brief(array $failure): string
    {
        $lines = [
            'Page: '.($failure['path'] ?? '/'),
            'Audit: '.($failure['id'] ?? '').' ('.($failure['category'] ?? 'other').')',
            'What Lighthouse says: '.($failure['title'] ?? ''),
            trim((string) ($failure['description'] ?? '')),
        ];

        foreach ((array) ($failure['elements'] ?? []) as $index => $element) {
            $lines[] = '';
            $lines[] = 'Element '.($index + 1).': '.($element['selector'] ?? '');
            $lines[] = $element['snippet'] ?? '';

            if (filled($element['explanation'] ?? null)) {
                $lines[] = 'Why: '.$element['explanation'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, PageScore>
     */
    private function latestScores(): Collection
    {
        return PageScore::query()
            ->orderByDesc('checked_at')
            ->get()
            ->unique(fn (PageScore $score): string => $score->path.'|'.$score->strategy)
            ->values();
    }

    /** Accessibility first: it is the one that shuts someone out. */
    private function rank(string $category): int
    {
        return match ($category) {
            'accessibility' => 0,
            'seo' => 1,
            'best-practices' => 2,
            default => 3,
        };
    }
}
