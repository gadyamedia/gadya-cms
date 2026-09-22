<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Notifications\DriftDigest;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Quality\Drift;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Notification;

/**
 * Websites do not fail, they drift. This is the fortnightly nudge about
 * the handful of things that have quietly gone out of date - and it says
 * nothing at all when nothing has.
 */
class DriftTest extends TestCase
{
    public function test_an_enquiry_nobody_opened_is_the_most_urgent_thing_on_the_site(): void
    {
        $this->publishDocument();

        FormSubmission::query()->create([
            'site_id' => 1,
            'form' => 'contact',
            'data' => ['name' => 'Ada', 'email' => 'ada@example.test'],
            'status' => FormSubmission::STATUS_NEW,
            'created_at' => now()->subDays(3),
        ]);

        $findings = app(Drift::class)->findings();

        $this->assertSame('unanswered-enquiries', $findings->first()['key']);
        $this->assertSame('now', $findings->first()['urgency']);
        $this->assertStringContainsString('3 days old', $findings->first()['says']);
        $this->assertStringContainsString('Somebody is waiting', $findings->first()['does']);
    }

    public function test_a_fresh_enquiry_is_not_nagged_about(): void
    {
        $this->publishDocument();

        FormSubmission::query()->create([
            'site_id' => 1,
            'form' => 'contact',
            'data' => ['name' => 'Ada'],
            'status' => FormSubmission::STATUS_NEW,
            'created_at' => now()->subHour(),
        ]);

        $this->assertNull(app(Drift::class)->findings()->firstWhere('key', 'unanswered-enquiries'), 'An hour is not neglect.');
        $this->assertSame(1, app(Drift::class)->unansweredLeads()['count']);
    }

    public function test_a_blog_nobody_has_touched_for_months_reads_as_a_closed_business(): void
    {
        $this->publishDocument();

        Post::query()->create([
            'site_id' => 1,
            'title' => 'Old news',
            'slug' => 'old-news',
            'content' => 'Something from a while ago.',
            'status' => 'published',
            'published_at' => now()->subMonths(8),
        ]);

        $finding = app(Drift::class)->findings()->firstWhere('key', 'quiet-blog');

        $this->assertNotNull($finding);
        $this->assertStringContainsString('newest article', $finding['says']);
    }

    public function test_photos_without_descriptions_are_mentioned_but_are_not_urgent(): void
    {
        $this->publishDocument();
        Media::factory()->create(['alt_text' => null]);

        $finding = app(Drift::class)->findings()->firstWhere('key', 'undescribed-photos');

        $this->assertSame('later', $finding['urgency']);
        $this->assertStringContainsString('Speed & accessibility', $finding['does']);
    }

    public function test_nothing_is_sent_when_nothing_has_drifted(): void
    {
        $this->publishDocument([
            'pages' => ['home' => ['title' => 'Home', 'seo' => ['meta_description' => 'A dental practice in Hove.']]],
        ]);
        config([
            'gadya-cms.seo.organization.telephone' => '01273 000000',
            'gadya-cms.seo.organization.email' => 'hello@acmedental.test',
            'gadya-cms.seo.organization.address' => '1 Church Road, Hove',
        ]);
        Notification::fake();

        $this->artisan('gadya-cms:drift-digest')->expectsOutputToContain('Nothing has drifted')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_digest_goes_to_whoever_asked_for_the_weekly_summary(): void
    {
        $this->publishDocument();
        Notification::fake();
        app(Options::class)->set('analytics.digest_recipients', ['owner@acmedental.test']);
        Media::factory()->create(['alt_text' => null]);

        $this->artisan('gadya-cms:drift-digest')->assertSuccessful();

        Notification::assertSentOnDemand(DriftDigest::class);
    }
}
