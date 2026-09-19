<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Resources\Pages\Pages\EditPage;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Seo\SeoHead;
use Gadya\Cms\Seo\SitemapEntries;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class SeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_a_page_without_a_snippet_falls_back_to_its_own_words(): void
    {
        config(['gadya-cms.seo.title_suffix' => ' | Springfield Parties', 'gadya-cms.seo.site_name' => 'Springfield Parties']);

        $tags = app(SeoHead::class)->tags(['title' => 'About us', 'description' => 'The people behind the site.', 'hero_image' => 'hero.webp'], 'http://cms.test/about');

        $this->assertSame('About us | Springfield Parties', $tags['title']);
        $this->assertSame('The people behind the site.', $tags['description']);
        $this->assertSame('http://cms.test/about', $tags['canonical']);
        $this->assertSame('index, follow', $tags['robots']);
        $this->assertStringContainsString('hero.webp', (string) $tags['image']);
        $this->assertSame('Springfield Parties', $tags['site_name']);
    }

    public function test_a_written_snippet_wins_and_noindex_is_honoured(): void
    {
        $tags = app(SeoHead::class)->tags([
            'title' => 'About us',
            'seo' => ['meta_title' => 'Who we are', 'meta_description' => 'Short.', 'canonical' => 'https://elsewhere.example/about', 'noindex' => true],
        ]);

        $this->assertSame('Who we are', $tags['title']);
        $this->assertSame('Short.', $tags['description']);
        $this->assertSame('https://elsewhere.example/about', $tags['canonical']);
        $this->assertSame('noindex, nofollow', $tags['robots']);
    }

    public function test_the_directive_renders_the_tags_into_the_head(): void
    {
        $html = app(SeoHead::class)->render(['title' => 'Pricing', 'description' => 'What it costs.'])->render();

        $this->assertStringContainsString('<title>Pricing</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="What it costs.">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<link rel="canonical"', $html);
    }

    public function test_a_draft_article_is_kept_out_of_search_engines(): void
    {
        $post = Post::factory()->create(['title' => 'Secret', 'excerpt' => 'Shh.']);

        $tags = app(SeoHead::class)->tags($post);

        $this->assertSame('noindex, nofollow', $tags['robots']);
        $this->assertSame('article', $tags['type']);
        $this->assertSame('Shh.', $tags['description']);
    }

    public function test_the_sitemap_lists_visible_pages_and_live_articles_only(): void
    {
        Post::factory()->published()->create(['slug' => 'live-one']);
        Post::factory()->create(['slug' => 'draft-one']);

        $document = app(SiteContentRepository::class)->draft();
        $document['pages']['pricing']['seo'] = ['noindex' => true];
        $this->publishDocument($document);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertSee('<loc>'.url('/').'</loc>', false)
            ->assertSee('<loc>'.url('/about').'</loc>', false)
            ->assertSee('<loc>'.url('/blog/live-one').'</loc>', false)
            ->assertDontSee('/pricing', false)
            ->assertDontSee('/old-offer', false)
            ->assertDontSee('draft-one', false);
    }

    public function test_a_site_can_add_its_own_records_to_the_sitemap(): void
    {
        SitemapEntries::add(fn (): array => ['/rentals/bouncy-castle', ['loc' => '/rentals/foam-party', 'lastmod' => '2026-09-01T00:00:00+00:00', 'priority' => '0.8']]);

        try {
            $this->get('/sitemap.xml')
                ->assertOk()
                ->assertSee('<loc>'.url('/rentals/bouncy-castle').'</loc>', false)
                ->assertSee('<loc>'.url('/rentals/foam-party').'</loc>', false)
                ->assertSee('2026-09-01T00:00:00+00:00', false)
                ->assertSee('0.8', false);
        } finally {
            SitemapEntries::flushExtenders();
        }
    }

    public function test_the_robots_file_points_at_the_sitemap_and_keeps_bots_out_of_the_panel(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /cms')
            ->assertSee('Sitemap: '.url('/sitemap.xml'));
    }

    public function test_the_snippet_fields_save_into_the_page_draft(): void
    {
        $page = Page::query()->where('slug', 'about')->firstOrFail();

        Livewire::actingAs($this->editor())
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm([
                'draft.seo.meta_title' => 'Who we are, really',
                'draft.seo.meta_description' => 'A snippet written by hand.',
                'draft.seo.noindex' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $seo = app(SiteContentRepository::class)->draft()['pages']['about']['seo'];

        $this->assertSame('Who we are, really', $seo['meta_title']);
        $this->assertTrue($seo['noindex']);
    }
}
