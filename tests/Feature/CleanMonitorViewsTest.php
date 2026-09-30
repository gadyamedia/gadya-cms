<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\PageView;
use Gadya\Cms\Tests\TestCase;

class CleanMonitorViewsTest extends TestCase
{
    private function views(string $visitor, string $path, int $count, string $day = '2026-09-10', ?string $referrer = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            PageView::query()->create([
                'path' => $path,
                'visitor_hash' => $visitor,
                'referrer_host' => $referrer,
                'viewed_at' => $day.' 12:'.str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT).':00',
            ]);
        }
    }

    private function seedDay(): void
    {
        $this->views('monitor', '/', 240);
        $this->views('human', '/pricing', 30);

        for ($i = 0; $i < 300; $i++) {
            $this->views('visitor-'.$i, '/', 1);
        }
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $this->seedDay();

        $this->artisan('gadya-cms:analytics:clean-monitors')
            ->expectsOutputToContain('Dry run: 240 views from 1 visitor-days would be removed')
            ->expectsOutputToContain('before its user agent was renamed')
            ->assertSuccessful();

        $this->assertSame(570, PageView::query()->count());
    }

    public function test_force_removes_the_monitor_and_nothing_else(): void
    {
        $this->seedDay();

        $this->artisan('gadya-cms:analytics:clean-monitors', ['--force' => true])
            ->expectsOutputToContain('Removed 240 views')
            ->assertSuccessful();

        $this->assertSame(0, PageView::query()->where('visitor_hash', 'monitor')->count());
        $this->assertSame(30, PageView::query()->where('visitor_hash', 'human')->count(), 'One person reloading is not a monitor.');
        $this->assertSame(300, PageView::query()->where('path', '/')->count(), 'A busy page of many visitors is untouched.');
    }

    public function test_a_visitor_with_a_referrer_and_a_campaign_or_many_pages_is_left_alone(): void
    {
        $this->views('mixed', '/', 150);
        $this->views('mixed', '/about', 100);
        $this->views('campaign', '/', 250);
        PageView::query()->where('visitor_hash', 'campaign')->first()->update(['utm_source' => 'newsletter']);

        $this->artisan('gadya-cms:analytics:clean-monitors', ['--force' => true])->assertSuccessful();

        $this->assertSame(500, PageView::query()->count());
    }

    public function test_min_views_and_path_narrow_the_search(): void
    {
        $this->views('a', '/', 60);
        $this->views('b', '/contact', 60);

        $this->artisan('gadya-cms:analytics:clean-monitors', ['--force' => true, '--min-views' => 50, '--path' => '/contact'])->assertSuccessful();

        $this->assertSame(60, PageView::query()->where('visitor_hash', 'a')->count());
        $this->assertSame(0, PageView::query()->where('visitor_hash', 'b')->count());
    }

    public function test_days_leaves_older_history_alone(): void
    {
        $this->views('old', '/', 250, now()->subDays(40)->toDateString());
        $this->views('recent', '/', 250, now()->subDay()->toDateString());

        $this->artisan('gadya-cms:analytics:clean-monitors', ['--force' => true, '--days' => 7])->assertSuccessful();

        $this->assertSame(250, PageView::query()->where('visitor_hash', 'old')->count());
        $this->assertSame(0, PageView::query()->where('visitor_hash', 'recent')->count());
    }
}
