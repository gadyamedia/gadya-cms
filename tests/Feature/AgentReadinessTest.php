<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Seo\AgentReadiness;
use Gadya\Cms\Seo\Markdown;
use Gadya\Cms\Seo\SeoHead;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class AgentReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_llms_txt_describes_the_site_its_pages_and_its_articles(): void
    {
        config(['gadya-cms.seo.site_name' => 'Springfield Parties', 'gadya-cms.seo.default_description' => 'Party hire in Springfield.', 'gadya-cms.seo.organization.telephone' => '555 010']);
        Post::factory()->published()->create(['slug' => 'plan', 'title' => 'How to plan', 'excerpt' => 'Plan it.']);
        Post::factory()->create(['slug' => 'draft', 'title' => 'Not yet']);

        $this->get('/llms.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertSee('# Springfield Parties')
            ->assertSee('> Party hire in Springfield.', false)
            ->assertSee('Telephone: 555 010')
            ->assertSee('['.'About us]('.url('/about').'): The people behind the site.', false)
            ->assertSee('[How to plan]('.url('/blog/plan').'): Plan it.', false)
            ->assertDontSee('Not yet')
            ->assertDontSee('old-offer')
            ->assertSee('Accept: text/markdown');
    }

    public function test_robots_names_the_ai_crawlers_it_welcomes_and_the_ones_it_refuses(): void
    {
        config(['gadya-cms.seo.ai_crawlers' => ['allow' => ['GPTBot'], 'block' => ['Bytespider']]]);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("User-agent: Bytespider\nDisallow: /", $robots);
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /admin\nDisallow: /cms\nAllow: /", $robots);
        $this->assertStringContainsString("User-agent: *\nDisallow: /admin", $robots);
        $this->assertStringContainsString('llms.txt', $robots);
    }

    public function test_every_page_carries_organisation_and_website_json_ld_and_articles_add_article(): void
    {
        config(['gadya-cms.seo.site_name' => 'Springfield Parties', 'gadya-cms.seo.organization' => ['type' => 'LocalBusiness', 'telephone' => '555 010', 'same_as' => ['https://instagram.com/springfield']]]);

        $html = app(SeoHead::class)->render(['title' => 'About'])->render();

        $this->assertStringContainsString('"@type":"LocalBusiness"', $html);
        $this->assertStringContainsString('"telephone":"555 010"', $html);
        $this->assertStringContainsString('"@type":"WebSite"', $html);
        $this->assertStringNotContainsString('"@type":"Article"', $html);

        $post = Post::factory()->published()->create(['title' => 'Planning']);
        $article = app(SeoHead::class)->render($post)->render();

        $this->assertStringContainsString('"@type":"Article"', $article);
        $this->assertStringContainsString('"headline":"Planning"', $article);
    }

    public function test_an_article_or_a_page_can_be_read_as_markdown(): void
    {
        $post = Post::factory()->published()->create([
            'slug' => 'plan',
            'title' => 'How to plan',
            'content' => '<h2>Start early</h2><p>Book <strong>six weeks</strong> ahead.</p>',
            'faq' => [['question' => 'When?', 'answer' => 'Early.']],
        ]);

        $this->get('/blog/plan', ['Accept' => 'text/markdown'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertHeader('Vary', 'Accept')
            ->assertSee('# How to plan')
            ->assertSee('## Start early')
            ->assertSee('Book **six weeks** ahead.')
            ->assertSee('**When?**');

        $this->get('/blog/plan', ['Accept' => 'text/html,*/*'])->assertOk()->assertSee('<article', false);

        $document = app(SiteContentRepository::class)->published();
        $expected = app(Markdown::class)->forPage($document['pages']['home'], url('/'));

        $this->assertStringContainsString('# Welcome to the site', $expected);
        $this->assertStringContainsString('## What we do', $expected);
        $this->assertStringContainsString('- **Parties** — We throw them.', $expected);

        Route::middleware('web')->get('/about', fn () => 'html page');
        $this->get('/about', ['Accept' => 'text/markdown'])->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->assertSee('# Who we are');
        $this->get('/about')->assertOk()->assertSee('html page');
        $this->get('/old-offer', ['Accept' => 'text/markdown'])->assertNotFound();
    }

    public function test_the_readiness_score_names_what_is_missing(): void
    {
        config(['gadya-cms.seo.llms' => false, 'gadya-cms.seo.site_name' => null, 'gadya-cms.seo.organization' => []]);
        $document = app(SiteContentRepository::class)->draft();
        unset($document['pages']['pricing']['description']);
        $this->publishDocument($document);

        $audit = app(AgentReadiness::class)->audit();
        $failing = collect($audit['checks'])->where('passed', false)->pluck('label')->all();

        $this->assertContains('llms.txt describes the site for AI assistants', $failing);
        $this->assertContains('Every visible page has a description', $failing);
        $this->assertLessThan(100, $audit['score']);

        config(['gadya-cms.seo.llms' => true, 'gadya-cms.seo.site_name' => 'Springfield']);
        $this->artisan('gadya-cms:agent-ready')->expectsOutputToContain('Agent readiness');
    }
}
