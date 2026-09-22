<?php

namespace Gadya\Cms\Support;

use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Quality\AccessibilityRecord;
use Gadya\Cms\Quality\Drift;
use Gadya\Cms\Quality\Failures;

/**
 * Everything the portal wants to know about this site in one place, so
 * `gadya/connect` can carry it in the check-in without reaching into a
 * dozen classes and without breaking when one of them changes.
 */
class PortalSummary
{
    public function __construct(
        private readonly Failures $failures,
        private readonly AccessibilityRecord $accessibility,
        private readonly Drift $drift,
        private readonly Backups $backups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'quality' => $this->quality(),
            'accessibility' => $this->accessibility(),
            'drift' => $this->drift(),
            'leads' => $this->drift->unansweredLeads(),
            'backups' => $this->backups->state(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function quality(): ?array
    {
        $latest = PageScore::query()->latest('checked_at')->first();

        if ($latest === null) {
            return null;
        }

        return [
            'checked_at' => $latest->checked_at?->toIso8601String(),
            'scores' => [
                'performance' => $latest->performance,
                'accessibility' => $latest->accessibility,
                'best_practices' => $latest->best_practices,
                'seo' => $latest->seo,
            ],
            'to_fix' => $this->failures->fixable()->count(),
            'for_developers' => $this->failures->forDevelopers()
                ->map(fn (array $failure): array => [
                    'id' => $failure['id'],
                    'title' => $failure['title'],
                    'path' => $failure['path'],
                ])
                ->take(20)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accessibility(): ?array
    {
        if (! $this->accessibility->exists()) {
            return null;
        }

        return [
            'score' => $this->accessibility->score(),
            'pages_checked' => $this->accessibility->pagesChecked(),
            'outstanding' => $this->accessibility->outstanding()->count(),
            'remediated' => $this->accessibility->remediated()->count(),
            'since' => $this->accessibility->since()?->toIso8601String(),
            'statement_url' => config('gadya-cms.accessibility.statement', true)
                ? url('/'.trim((string) config('gadya-cms.accessibility.path', 'accessibility-statement'), '/'))
                : null,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function drift(): array
    {
        return $this->drift->findings()
            ->map(fn (array $finding): array => [
                'key' => $finding['key'],
                'urgency' => $finding['urgency'],
                'says' => $finding['says'],
            ])
            ->all();
    }
}
