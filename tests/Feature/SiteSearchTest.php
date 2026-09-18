<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Search\SiteSearch;
use Gadya\Cms\Tests\TestCase;

class SiteSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_it_searches_pages_and_articles_and_puts_titles_first(): void
    {
        Post::factory()->published()->create(['title' => 'Pricing explained', 'excerpt' => 'What it costs', 'slug' => 'pricing-explained']);
        Post::factory()->published()->create(['title' => 'Something else', 'content' => '<p>We mention pricing once here.</p>', 'slug' => 'something-else']);

        $results = app(SiteSearch::class)->for('pricing');
        $titles = $results->pluck('title')->all();

        $this->assertContains('Pricing explained', $titles, 'The article named for it is found.');
        $this->assertContains('What it costs', $titles, 'So is the page named Pricing.');
        $this->assertSame('Something else', end($titles), 'A word buried in the body ranks below the same word in a title.');
        $this->assertSame(['Article', 'Page'], collect($results)->pluck('kind')->unique()->sort()->values()->all());
        $this->assertStringContainsString('pricing', strtolower($results[0]['snippet']));
    }

    public function test_the_results_page_finds_a_page_by_its_words(): void
    {
        $this->get('/search?q=introduction')
            ->assertOk()
            ->assertSee('Welcome to the site')
            ->assertSee(url('/'), false)
            ->assertSee('1 result');
    }

    public function test_hidden_and_noindex_pages_are_never_found(): void
    {
        $results = app(SiteSearch::class)->for('hidden');

        $this->assertSame([], $results->pluck('title')->all());
    }

    public function test_an_empty_or_tiny_query_finds_nothing_and_says_so(): void
    {
        $this->assertCount(0, app(SiteSearch::class)->for(''));
        $this->assertCount(0, app(SiteSearch::class)->for('a'));

        $this->get('/search')->assertOk()->assertSee('Search this site')->assertDontSee('cms-search__result"', false);
        $this->get('/search?q=zzzznothing')->assertOk()->assertSee('Nothing matched.');
    }

    public function test_the_results_page_keeps_itself_out_of_search_engines(): void
    {
        $this->get('/search?q=pricing')->assertOk()->assertSee('noindex, nofollow', false);
    }

    public function test_what_people_search_for_is_counted_including_what_they_do_not_find(): void
    {
        $this->get('/search?q=Zzzz%20Nothing');
        $this->get('/search?q=pricing');

        $searches = AnalyticsEvent::query()->where('name', 'site_search')->orderBy('id')->get();

        $this->assertCount(2, $searches);
        $this->assertSame('zzzz nothing', $searches[0]->metadata['term'], 'Kept in one case, so the same search is one search.');
        $this->assertSame('0', $searches[0]->metadata['results'], 'A search with nothing behind it is the useful one.');
        $this->assertNotSame('0', $searches[1]->metadata['results']);
    }
}
