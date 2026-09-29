<?php

namespace Gadya\Cms\Support;

use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Hours\BusinessHours;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Portal\ChangeRequests;
use Gadya\Cms\Privacy\Consent;
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
        private readonly BackupDrill $drill,
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
            'backups' => [...$this->backups->state(), 'drill' => $this->drill->last()],
            'change_requests' => rescue(fn (): array => app(ChangeRequests::class)->summary(), [], report: false),
            'locales' => app(Locales::class)->summary(),
            ...$this->hours(),
            'consent' => app(Consent::class)->summary(),
            'forms' => $this->forms(),
        ];
    }

    /**
     * How many forms the site has - built and configured, live ones only -
     * and how many enquiries arrived through any of them this month.
     *
     * @return array{count: int, submissions_last_30_days: int}
     */
    private function forms(): array
    {
        $siteId = app(SiteContext::class)->id();
        $built = rescue(fn (): array => Form::query()->where('site_id', $siteId)->live()->pluck('slug')->all(), [], report: false);

        return [
            'count' => count(array_unique([...$built, ...array_keys(FormDefinition::configLabels())])),
            'submissions_last_30_days' => (int) rescue(fn (): int => FormSubmission::query()->where('site_id', $siteId)->where('created_at', '>=', now()->subDays(30))->count(), 0, report: false),
        ];
    }

    /**
     * The opening hours, left out altogether when none are set.
     *
     * @return array{hours?: array<string, mixed>}
     */
    private function hours(): array
    {
        $hours = rescue(fn (): ?array => app(BusinessHours::class)->summary(), null, report: false);

        return $hours === null ? [] : ['hours' => $hours];
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
