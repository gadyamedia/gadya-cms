<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Search\PageSpeed;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class PageSpeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    /**
     * @return array<string, mixed>
     */
    private function lighthouse(float $performance): array
    {
        return ['lighthouseResult' => [
            'categories' => [
                'performance' => ['score' => $performance],
                'accessibility' => ['score' => 0.97],
                'best-practices' => ['score' => 1],
                'seo' => ['score' => 0.92],
            ],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 2140.5],
                'cumulative-layout-shift' => ['numericValue' => 0.012],
                'render-blocking-resources' => ['title' => 'Eliminate render-blocking resources', 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 450]],
                'unused-css' => ['title' => 'Reduce unused CSS', 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 50]],
            ],
        ]];
    }

    public function test_a_check_records_the_scores_and_the_biggest_savings(): void
    {
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::response($this->lighthouse(0.63))]);

        $score = app(PageSpeed::class)->check('http://cms.test/pricing');

        $this->assertSame('/pricing', $score->path);
        $this->assertSame(63, $score->performance);
        $this->assertSame(97, $score->accessibility);
        $this->assertSame(100, $score->best_practices);
        $this->assertSame(92, $score->seo);
        $this->assertSame(2140, $score->lcp_ms);
        $this->assertSame(0.012, $score->cls);
        $this->assertSame([['title' => 'Eliminate render-blocking resources', 'savings_ms' => 450]], $score->opportunities, 'Savings under 100ms are noise.');
    }

    public function test_the_site_check_covers_the_top_pages_and_the_dashboard_shows_the_latest_per_page(): void
    {
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::sequence()->push($this->lighthouse(0.5))->push($this->lighthouse(0.9))->push($this->lighthouse(0.7))]);

        $this->artisan('gadya-cms:pagespeed', ['--limit' => 2])->expectsOutputToContain('/about')->assertSuccessful();
        app(PageSpeed::class)->check(url('/'));

        $latest = app(PageSpeed::class)->latest();

        $this->assertCount(2, $latest);
        $this->assertSame(70, $latest->firstWhere('path', '/')->performance, 'The newer check of the home page wins.');
        $this->assertSame(3, PageScore::query()->count(), 'History is kept.');
    }

    public function test_a_failed_check_is_reported_not_stored(): void
    {
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        $this->artisan('gadya-cms:pagespeed', ['--url' => 'http://cms.test/'])->expectsOutputToContain('Quota exceeded')->assertFailed();

        $this->assertSame(0, PageScore::query()->count());
    }
}
