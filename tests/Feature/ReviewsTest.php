<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Portal\Reviews;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

/**
 * The business's Google reviews on its own site, through the portal:
 * the good ones, credited to Google, with a way to leave another - and
 * nothing at all when there are none to show.
 */
class ReviewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function connect(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return ['data' => [
            'rating' => 4.6,
            'count' => 87,
            'review_url' => 'https://search.google.com/local/writereview?placeid=abc',
            'reviews' => [
                ['author' => 'Priya S', 'rating' => 5, 'text' => 'Gentle, kind and on time.', 'relative_time' => 'a week ago', 'time' => '2026-09-20T10:00:00+00:00', 'profile_photo_url' => 'https://lh3.googleusercontent.com/p.png'],
                ['author' => 'Tom B', 'rating' => 2, 'text' => 'Parking was hard.', 'relative_time' => 'a month ago', 'time' => null, 'profile_photo_url' => null],
                ['author' => 'Jo', 'rating' => 4, 'text' => 'Friendly <b>team</b>.', 'relative_time' => '2 months ago', 'time' => null, 'profile_photo_url' => null],
            ],
        ]];
    }

    public function test_the_good_reviews_are_shown_credited_to_google_with_a_way_to_leave_one(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response($this->answer())]);

        $html = Blade::render('<x-gadya-cms::reviews />');

        $this->assertStringContainsString('Gentle, kind and on time.', $html);
        $this->assertStringContainsString('Priya S', $html);
        $this->assertStringContainsString('Friendly &lt;b&gt;team&lt;/b&gt;.', $html, 'A review is text, never markup.');
        $this->assertStringNotContainsString('Parking was hard', $html, 'Below four stars is left to Google.');
        $this->assertStringContainsString('Rated 4.6 out of 5 from 87 reviews', $html);
        $this->assertStringContainsString('Reviews from Google', $html);
        $this->assertStringContainsString('href="https://search.google.com/local/writereview?placeid=abc"', $html);
        $this->assertStringContainsString('Leave us a review', $html);
        $this->assertStringNotContainsString('AggregateRating', $html);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://portal.test'.Reviews::PATH
            && $request->hasHeader('X-Gadya-Signature'));
    }

    public function test_the_portal_is_asked_at_most_every_twelve_hours(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response($this->answer())]);

        Blade::render('<x-gadya-cms::reviews />');
        Blade::render('<x-gadya-cms::reviews />');

        Http::assertSentCount(1);

        $this->travel(13)->hours();
        Blade::render('<x-gadya-cms::reviews />');

        Http::assertSentCount(2);
    }

    public function test_the_site_chooses_how_many_and_how_good(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response($this->answer())]);

        config(['gadya-cms.portal.reviews.min_rating' => 2, 'gadya-cms.portal.reviews.limit' => 2]);

        $html = Blade::render('<x-gadya-cms::reviews />');

        $this->assertStringContainsString('Parking was hard', $html);
        $this->assertStringNotContainsString('Friendly', $html, 'Only two.');

        $html = Blade::render('<x-gadya-cms::reviews :limit="1" :min-rating="5" heading="Kind words" />');

        $this->assertStringContainsString('Kind words', $html);
        $this->assertSame(1, substr_count($html, 'cms-reviews__item'));
    }

    public function test_nothing_is_rendered_when_there_is_nothing_to_show(): void
    {
        $this->assertSame('', trim(Blade::render('<x-gadya-cms::reviews />')), 'Not paired.');

        app(Reviews::class)->forget();
        $this->connect();
        Http::fake(['portal.test/*' => Http::response(['message' => 'Server error'], 500)]);

        $this->assertSame('', trim(Blade::render('<x-gadya-cms::reviews />')), 'Portal down.');

        app(Reviews::class)->forget();
        Http::fake(['portal.test/*' => Http::response(['data' => ['rating' => null, 'count' => null, 'review_url' => null, 'reviews' => []]])]);

        $this->assertSame('', trim(Blade::render('<x-gadya-cms::reviews />')), 'No reviews and nowhere to leave one.');
    }
}
