<?php

namespace Gadya\Cms\Analytics;

use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormEvent;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Support\SiteTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * How a built form is doing, from the CMS's own counting: how many saw
 * it, started it and sent it, where on a long form people give up, how
 * long it takes, and where they came from.
 *
 * People are counted by the same daily identifier as the rest of the
 * dashboard, so "people" means people on a day. Someone who said no to
 * analytics is not counted seeing or starting a form at all, and their
 * enquiry is counted without an identifier - so on a site with the
 * privacy banner, sends can outnumber starts.
 */
class FormAnalytics
{
    /**
     * @return array{views: int, starts: int, completions: int, conversion: float, start_rate: float, average_seconds: int|null, steps: list<array{step: int, title: string, people: int, dropped: int}>, sources: array<string, int>, pages: array<string, int>}
     */
    public function for(Form $form, int $days = 30): array
    {
        $since = $this->windowStart($days);
        $views = $this->people($form, FormEvent::VIEW, $since);
        $starts = $this->people($form, FormEvent::START, $since);
        $completions = $this->query($form, FormEvent::COMPLETE, $since)->count();
        $average = $this->query($form, FormEvent::COMPLETE, $since)->whereNotNull('duration_seconds')->avg('duration_seconds');

        return [
            'views' => $views,
            'starts' => $starts,
            'completions' => $completions,
            'conversion' => $views > 0 ? round(min(100, $completions / $views * 100), 1) : 0.0,
            'start_rate' => $views > 0 ? round(min(100, $starts / $views * 100), 1) : 0.0,
            'average_seconds' => $average === null ? null : (int) round((float) $average),
            'steps' => $this->steps($form, $since, $starts, $completions),
            'sources' => $this->grouped($form, 'referrer_host', $since, [FormEvent::VIEW]),
            'pages' => $this->grouped($form, 'path', $since, [FormEvent::VIEW, FormEvent::COMPLETE]),
        ];
    }

    /**
     * The first moment of the business's day, `days` days ago.
     */
    private function windowStart(int $days): Carbon
    {
        $zone = app(SiteTimezone::class);

        return Carbon::instance($zone->startOfLocalDay($zone->now()->subDays($days)));
    }

    /**
     * Every form with anything to show, busiest first, for the dashboard.
     *
     * @return list<array{form: Form, views: int, completions: int, conversion: float}>
     */
    public function overview(int $days = 30, int $limit = 6): array
    {
        $since = $this->windowStart($days);
        $rows = [];

        foreach (Form::query()->forCurrentSite()->where('status', '!=', Form::STATUS_ARCHIVED)->get() as $form) {
            $views = $this->people($form, FormEvent::VIEW, $since);
            $completions = $this->query($form, FormEvent::COMPLETE, $since)->count();

            if ($views === 0 && $completions === 0) {
                continue;
            }

            $rows[] = [
                'form' => $form,
                'views' => $views,
                'completions' => $completions,
                'conversion' => $views > 0 ? round(min(100, $completions / $views * 100), 1) : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['completions'], $b['views']] <=> [$a['completions'], $a['views']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * How many reach each step of a long form, and how many of those who
     * reached one never reached the next.
     *
     * @return list<array{step: int, title: string, people: int, dropped: int}>
     */
    private function steps(Form $form, Carbon $since, int $starts, int $completions): array
    {
        $steps = $form->schema()->steps($form->message('first_step'));

        if (count($steps) < 2) {
            return [];
        }

        $reached = $this->query($form, FormEvent::STEP, $since)
            ->whereNotNull('step')
            ->selectRaw('step, count(distinct visitor_hash) as people')
            ->groupBy('step')
            ->pluck('people', 'step');

        $rows = [];

        foreach ($steps as $step) {
            $rows[] = [
                'step' => $step['index'] + 1,
                'title' => $step['title'] !== '' ? $step['title'] : 'Step '.($step['index'] + 1),
                'people' => $step['index'] === 0 ? $starts : (int) ($reached[$step['index']] ?? 0),
            ];
        }

        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1]['people'] ?? $completions;
            $rows[$index]['dropped'] = max(0, $row['people'] - $next);
        }

        return $rows;
    }

    private function people(Form $form, string $name, Carbon $since): int
    {
        return (int) $this->query($form, $name, $since)->distinct()->count('visitor_hash');
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function grouped(Form $form, string $column, Carbon $since, array $names): array
    {
        return FormEvent::query()
            ->where('form_id', $form->getKey())
            ->whereIn('name', $names)
            ->where('created_at', '>=', $since)
            ->whereNotNull($column)
            ->selectRaw($column.' as label, count(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'label')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @return Builder<FormEvent>
     */
    private function query(Form $form, string $name, Carbon $since)
    {
        return FormEvent::query()
            ->where('site_id', app(SiteContext::class)->id())
            ->where('form_id', $form->getKey())
            ->where('name', $name)
            ->where('created_at', '>=', $since);
    }
}
