<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Quality\Visibility;
use Gadya\Cms\Support\PortalSummary;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Support\Facades\Http;

/**
 * What the site tells the portal about itself, so one screen can show
 * every client without opening any of them.
 */
class PortalSummaryTest extends TestCase
{
    public function test_the_summary_carries_the_scores_the_record_the_drift_and_the_waiting_enquiries(): void
    {
        $this->publishDocument();

        PageScore::query()->create([
            'site_id' => 1,
            'path' => '/',
            'strategy' => 'mobile',
            'performance' => 62,
            'accessibility' => 88,
            'seo' => 91,
            'failures' => [
                ['id' => 'image-alt', 'title' => 'Images have no alt', 'description' => '', 'category' => 'accessibility', 'elements' => []],
                ['id' => 'aria-allowed-attr', 'title' => 'ARIA not permitted', 'description' => '', 'category' => 'accessibility', 'elements' => []],
            ],
            'checked_at' => now(),
        ]);

        Media::factory()->create(['alt_text' => null]);

        $summary = app(PortalSummary::class)->build();

        $this->assertSame(62, $summary['quality']['scores']['performance']);
        $this->assertSame(1, $summary['quality']['to_fix'], 'Only the audit the CMS can fix itself.');
        $this->assertSame('aria-allowed-attr', $summary['quality']['for_developers'][0]['id']);

        $this->assertSame(88, $summary['accessibility']['score']);
        $this->assertSame(2, $summary['accessibility']['outstanding']);
        $this->assertStringContainsString('accessibility-statement', (string) $summary['accessibility']['statement_url']);

        $this->assertSame(0, $summary['leads']['count']);
        $this->assertContains('undescribed-photos', array_column($summary['drift'], 'key'));
        $this->assertFalse($summary['backups']['configured'], 'A site that takes no backups says so, which is worth knowing.');
    }

    public function test_the_check_in_the_installed_connect_sends_carries_the_summary(): void
    {
        /*
         * The summary is only worth building if it reaches the portal.
         * gadya/connect before 0.5 sends a check-in without it, and for a
         * while every site was held back to 0.4 by this package's own
         * constraint, so the fleet screen received nothing.
         */
        $this->publishDocument();

        $report = app(\Gadya\Connect\Report\ReportBuilder::class)->build();

        foreach (['leads', 'backups', 'drift'] as $section) {
            $this->assertArrayHasKey($section, $report, "The check-in must carry {$section}; is gadya/connect held below 0.5?");
        }
    }

    public function test_a_site_never_checked_reports_no_quality_rather_than_zeroes(): void
    {
        $this->publishDocument();

        $summary = app(PortalSummary::class)->build();

        $this->assertNull($summary['quality']);
        $this->assertNull($summary['accessibility']);
    }

    public function test_the_panel_reads_the_assistants_answers_from_the_portal(): void
    {
        $this->publishDocument();
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh', 'site_name' => 'Acme Dental']);
        config(['gadya-cms.mail.shared' => true]);

        Http::fake(['portal.test/*' => Http::response([
            'checked_at' => now()->toIso8601String(),
            'asked' => 2,
            'named' => 1,
            'queries' => [['query' => 'best dentist in Hove', 'named' => true, 'position' => 2, 'excerpt' => 'Twenty years in Hove.']],
        ])]);

        $latest = app(Visibility::class)->latest();

        $this->assertSame(1, $latest['named']);
        $this->assertSame('best dentist in Hove', $latest['queries'][0]['query']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://portal.test/api/connect/v1/visibility');
    }
}
