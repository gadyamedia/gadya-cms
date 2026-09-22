<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\Fix;
use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Quality\AccessibilityRecord;
use Gadya\Cms\Tests\TestCase;

/**
 * The statement claims only what the record supports. An overlay promises
 * conformance nobody tested; this says what was checked, what was fixed
 * and what is still wrong, with dates - which is the thing worth having
 * when somebody's lawyer writes in.
 */
class AccessibilityRecordTest extends TestCase
{
    private function score(array $failures, string $path = '/', ?string $at = null, int $accessibility = 80): PageScore
    {
        return PageScore::query()->create([
            'site_id' => 1,
            'path' => $path,
            'strategy' => 'mobile',
            'accessibility' => $accessibility,
            'failures' => $failures,
            'checked_at' => $at ?? now()->toDateTimeString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function barrier(string $id, string $title): array
    {
        return ['id' => $id, 'title' => $title, 'description' => '', 'category' => 'accessibility', 'elements' => []];
    }

    public function test_it_never_claims_full_conformance_even_with_a_clean_run(): void
    {
        $this->publishDocument();
        $this->score([], accessibility: 100);

        $statement = app(AccessibilityRecord::class)->statement();

        $this->assertStringContainsString('Partially conformant', $statement['conformance']);
        $this->assertStringContainsString('can only be judged by a person', $statement['conformance']);
        $this->assertSame('WCAG 2.2 level AA', $statement['target']);
    }

    public function test_outstanding_barriers_are_listed_and_counted(): void
    {
        $this->publishDocument();
        $this->score([
            $this->barrier('aria-allowed-attr', 'Elements must only use permitted ARIA attributes'),
            $this->barrier('image-alt', 'Image elements do not have [alt] attributes'),
        ]);

        $record = app(AccessibilityRecord::class);

        $this->assertCount(2, $record->outstanding());
        $this->assertStringContainsString('2 known issues', $record->statement()['conformance']);
    }

    public function test_a_barrier_gone_from_the_latest_check_is_recorded_as_fixed_with_the_date_it_went(): void
    {
        $this->publishDocument();
        $this->score([$this->barrier('aria-allowed-attr', 'Elements must only use permitted ARIA attributes')], at: now()->subMonth()->toDateTimeString());
        $this->score([], at: now()->toDateTimeString());

        $remediated = app(AccessibilityRecord::class)->remediated();

        $this->assertCount(1, $remediated);
        $this->assertSame('Elements must only use permitted ARIA attributes', $remediated->first()['what']);
        $this->assertTrue($remediated->first()['on']->isToday(), 'The date is the check that no longer saw it.');
        $this->assertSame('Gadya Media', $remediated->first()['by']);
    }

    public function test_the_fixes_the_cms_made_are_in_the_record_too(): void
    {
        $this->publishDocument();
        $this->score([]);
        Fix::query()->create(['audit' => 'image-alt', 'subject' => 'cake.webp', 'after' => 'Children around a cake', 'written_by' => 'gadya']);

        $remediated = app(AccessibilityRecord::class)->remediated();

        $this->assertStringContainsString('screen readers', $remediated->first()['what']);
        $this->assertSame('cake.webp', $remediated->first()['where']);
    }

    public function test_the_public_statement_reads_as_a_page_and_says_how_to_report_a_barrier(): void
    {
        $this->publishDocument();
        config(['gadya-cms.seo.organization.email' => 'hello@acmedental.test', 'gadya-cms.seo.site_name' => 'Acme Dental']);
        $this->score([$this->barrier('aria-allowed-attr', 'Elements must only use permitted ARIA attributes')]);

        $this->get('/accessibility-statement')
            ->assertOk()
            ->assertSee('Acme Dental')
            ->assertSee('WCAG 2.2 level AA')
            ->assertSee('Elements must only use permitted ARIA attributes')
            ->assertSee('hello@acmedental.test');
    }

    public function test_the_statement_is_in_the_sitemap_like_any_other_page(): void
    {
        $this->publishDocument();

        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/accessibility-statement'));
    }

    public function test_a_site_that_has_never_been_checked_says_so_rather_than_claiming_anything(): void
    {
        $this->publishDocument();

        $this->get('/accessibility-statement')
            ->assertOk()
            ->assertSee('has not yet been checked');
    }
}
