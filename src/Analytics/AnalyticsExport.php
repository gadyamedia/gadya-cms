<?php

namespace Gadya\Cms\Analytics;

use Gadya\Cms\Models\PageView;
use Gadya\Cms\Support\SiteTimezone;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The raw page views for a period, as a spreadsheet: every row a visit,
 * with the day, page, where from and on what. No visitor identifier is
 * included - there is none worth exporting.
 */
class AnalyticsExport
{
    public function download(int $days): StreamedResponse
    {
        $zone = app(SiteTimezone::class);
        $since = $zone->startOfLocalDay($zone->now()->subDays($days));

        return response()->streamDownload(function () use ($since, $zone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Time', 'Page', 'Referrer', 'Source', 'Medium', 'Campaign', 'Device', 'Country', 'Region', 'City']);

            PageView::query()->since($since)->orderBy('viewed_at')->chunkById(1000, function ($views) use ($out, $zone): void {
                foreach ($views as $view) {
                    fputcsv($out, [
                        $zone->format($view->viewed_at, 'Y-m-d'),
                        $zone->format($view->viewed_at, 'H:i'),
                        $view->path,
                        $view->referrer_host,
                        $view->utm_source,
                        $view->utm_medium,
                        $view->utm_campaign,
                        $view->device_category,
                        $view->country,
                        $view->region,
                        $view->city,
                    ]);
                }
            });

            fclose($out);
        }, 'visits-'.$zone->now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
