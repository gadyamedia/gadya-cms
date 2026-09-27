<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Models\PageView;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Services\ManagePages;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\PortalSummary;
use Gadya\Cms\Tests\TestCase;

/**
 * Many of the businesses these sites belong to serve customers in
 * Spanish as much as in English. With a second language switched on,
 * every page is also served under /es/, in Spanish wherever it has been
 * translated and in English wherever it has not - never blank.
 */
class MultilingualTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gadya-cms.locales.enabled' => ['en', 'es']]);

        $this->publishDocument();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/{slug?}', function (?string $slug = null) {
            $editor = app(EditContext::class);
            $editor->boot();

            $slug ??= 'home';
            $site = app(PublicDocument::class)->from(app(SiteContentRepository::class)->forRequest());
            $page = $site['pages'][$slug] ?? abort(404);

            abort_if(app(PageRegistry::class)->isHidden($page) && ! $editor->showsDraft(), 404);

            $editor->for("pages.{$slug}");

            return view('pages.localised', ['page' => $page, 'site' => $site, 'slug' => $slug]);
        })->middleware('web')->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    }

    public function test_a_translated_page_is_served_in_spanish_under_its_prefix(): void
    {
        $this->translateAndPublish('pages.about', ['heading' => 'Quiénes somos']);

        $this->get('/es/about')
            ->assertOk()
            ->assertSee('<html lang="es">', false)
            ->assertSee('Quiénes somos');

        $this->get('/about')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Who we are')
            ->assertDontSee('Quiénes somos');
    }

    public function test_words_nobody_has_translated_yet_fall_back_to_english_never_to_a_blank(): void
    {
        $this->translateAndPublish('pages.about', ['heading' => 'Quiénes somos', 'description' => '   ']);

        $this->get('/es/about')
            ->assertOk()
            ->assertSee('Quiénes somos')
            ->assertSee('The people behind the site.');
    }

    public function test_the_spanish_home_page_lives_at_the_bare_prefix(): void
    {
        $this->translateAndPublish('pages.home', ['heading' => 'Bienvenidos']);

        $this->get('/es')->assertOk()->assertSee('Bienvenidos');
        $this->get('/es/')->assertOk()->assertSee('Bienvenidos');
    }

    public function test_links_on_a_spanish_page_stay_on_the_spanish_site_and_assets_do_not(): void
    {
        $this->translateAndPublish('nav', [1 => ['label' => 'Nosotros']]);

        $this->get('/es/about')
            ->assertOk()
            ->assertSee('<a href="http://localhost/es/about">Nosotros</a>', false)
            ->assertSee('<a href="http://localhost/es">Home</a>', false)
            ->assertSee('href="http://localhost/build/app.css"', false);
    }

    public function test_the_default_language_has_one_address_only(): void
    {
        $this->get('/en/about?ref=flyer')->assertRedirect('http://localhost/about?ref=flyer')->assertStatus(301);
    }

    public function test_the_head_names_every_language_version_and_its_own_canonical(): void
    {
        $this->get('/es/about')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/es/about">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="http://localhost/about">', false)
            ->assertSee('<link rel="alternate" hreflang="es" href="http://localhost/es/about">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="http://localhost/about">', false)
            ->assertSee('<meta property="og:locale" content="es">', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="alternate" hreflang="es" href="http://localhost/es">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="http://localhost">', false);
    }

    public function test_the_switcher_links_to_the_same_page_in_the_other_language(): void
    {
        $this->get('/es/about')
            ->assertSee('<a class="cms-languages__link" href="http://localhost/about" hreflang="en" lang="en">English</a>', false)
            ->assertSee('aria-current="true">Español</span>', false);
    }

    public function test_the_sitemap_lists_every_page_in_every_language_with_its_alternates(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $response->assertSee('xmlns:xhtml="http://www.w3.org/1999/xhtml"', false)
            ->assertSee('<loc>http://localhost/about</loc>', false)
            ->assertSee('<loc>http://localhost/es/about</loc>', false)
            ->assertSee('<xhtml:link rel="alternate" hreflang="es" href="http://localhost/es/about"/>', false)
            ->assertSee('<xhtml:link rel="alternate" hreflang="x-default" href="http://localhost/about"/>', false)
            ->assertDontSee('/es/old-offer', false);
    }

    public function test_llms_txt_stays_in_the_default_language_and_says_where_the_others_are(): void
    {
        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('- [About us](http://localhost/about)', false)
            ->assertSee('## Languages', false)
            ->assertSee('Español (es): http://localhost/es', false);
    }

    public function test_a_draft_translation_is_not_live_until_it_is_published(): void
    {
        app(Translations::class)->store('pages.about', 'es', ['heading' => 'Quiénes somos'], machine: false);

        $this->get('/es/about')->assertOk()->assertDontSee('Quiénes somos');

        app(PublishSiteContent::class)->handle();

        $this->get('/es/about')->assertOk()->assertSee('Quiénes somos');
    }

    public function test_a_machine_translation_is_held_back_from_publishing_until_someone_has_reviewed_it(): void
    {
        app(Translations::class)->store('pages.about', 'es', ['heading' => 'Quiénes somos'], machine: true);

        app(PublishSiteContent::class)->handle();
        $this->get('/es/about')->assertOk()->assertDontSee('Quiénes somos');

        app(Translations::class)->approve('pages.about', 'es', $this->editor());
        app(PublishSiteContent::class)->handle();

        $this->get('/es/about')->assertOk()->assertSee('Quiénes somos');
        $this->assertNotNull(Translation::query()->sole()->reviewed_at);
    }

    public function test_an_editor_on_a_spanish_page_edits_the_spanish_words_and_leaves_the_english_alone(): void
    {
        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->postJson('/es/cms/inline', ['path' => 'pages.about.heading', 'value' => 'Quiénes somos'])
            ->assertOk();

        $this->assertSame('Who we are', app(SiteContentRepository::class)->draft()['pages']['about']['heading']);
        $this->assertSame(['heading' => 'Quiénes somos'], Translation::query()->where('key', 'pages.about')->sole()->draft);

        $this->get('/es/about')
            ->assertSee('Quiénes somos')
            ->assertSee('data-cms-base="/es"', false)
            ->assertSee('in Español', false);

        $this->withSession([EditContext::SESSION_KEY => false])->get('/es/about')->assertDontSee('Quiénes somos');
    }

    public function test_the_toolbar_on_a_machine_translated_page_asks_for_a_review(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        app(Translations::class)->store('pages.about', 'es', ['heading' => 'Quiénes somos'], machine: true);

        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->get('/es/about')
            ->assertOk()
            ->assertSee('Quiénes somos')
            ->assertSee('Machine translated')
            ->assertSee('action="http://localhost/es/cms/translations/review"', false)
            ->assertSee('Translate again into Spanish')
            ->assertSee('<a class="gadya-cms-link" href="http://localhost/about" hreflang="en" lang="en">English</a>', false);
    }

    public function test_a_photo_changed_on_a_spanish_page_changes_it_in_every_language(): void
    {
        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->postJson('/es/cms/inline', ['path' => 'pages.home.hero_image', 'value' => 'summer.webp'])
            ->assertOk();

        $this->assertSame('summer.webp', app(SiteContentRepository::class)->draft()['pages']['home']['hero_image']);
        $this->assertSame(0, Translation::query()->count());
    }

    public function test_the_editor_saves_to_the_english_site_without_a_prefix(): void
    {
        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->postJson('/cms/inline', ['path' => 'pages.about.heading', 'value' => 'All about us'])
            ->assertOk();

        $this->assertSame('All about us', app(SiteContentRepository::class)->draft()['pages']['about']['heading']);
        $this->assertSame(0, Translation::query()->count());
    }

    public function test_a_translated_card_follows_its_card_when_the_cards_are_reordered(): void
    {
        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['home']['sections'][0]['items'] = [
            ['key' => 'k-parties', 'title' => 'Parties'],
            ['key' => 'k-camps', 'title' => 'Camps'],
        ];
        $repository->saveDraft($document);

        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->postJson('/es/cms/inline', ['path' => 'pages.home.sections.0.items.1.title', 'value' => 'Campamentos'])
            ->assertOk();

        $document = $repository->draft();
        $document['pages']['home']['sections'][0]['items'] = array_reverse($document['pages']['home']['sections'][0]['items']);
        $repository->saveDraft($document);

        $spanish = $repository->draftIn('es')['pages']['home']['sections'][0]['items'];

        $this->assertSame('Campamentos', $spanish[0]['title']);
        $this->assertSame('Parties', $spanish[1]['title']);
    }

    public function test_an_article_is_read_in_spanish_under_the_prefix(): void
    {
        $post = Post::factory()->published()->create(['slug' => 'foam-parties', 'title' => 'Foam parties explained']);
        app(Translations::class)->store('post:'.$post->id, 'es', ['title' => 'Fiestas de espuma'], machine: false);
        app(PublishSiteContent::class)->handle();

        $this->get('/es/blog/foam-parties')->assertOk()->assertSee('Fiestas de espuma');
        $this->get('/blog/foam-parties')->assertOk()->assertSee('Foam parties explained')->assertDontSee('Fiestas de espuma');

        $this->assertSame('Foam parties explained', $post->fresh()->title, 'The translation is never written over the original.');
    }

    public function test_a_page_renamed_keeps_its_translation(): void
    {
        $this->translateAndPublish('pages.about', ['heading' => 'Quiénes somos']);

        app(ManagePages::class)->rename('about', 'our-story');
        app(PublishSiteContent::class)->handle();

        $this->get('/es/our-story')->assertOk()->assertSee('Quiénes somos');
    }

    public function test_a_page_cannot_take_a_language_code_as_its_address(): void
    {
        $this->assertTrue(app(PageRegistry::class)->isReserved('es'));
    }

    public function test_a_visit_to_a_spanish_page_is_counted_under_its_own_address(): void
    {
        $this->get('/es/about')->assertOk();

        $this->assertSame('/es/about', PageView::query()->sole()->path);
    }

    public function test_with_one_language_the_site_is_exactly_as_it_was(): void
    {
        config(['gadya-cms.locales.enabled' => ['en']]);
        app(Translations::class)->store('pages.about', 'es', ['heading' => 'Quiénes somos'], machine: false);
        app(PublishSiteContent::class)->handle();

        $this->get('/es/about')->assertNotFound();
        $this->get('/en/about')->assertNotFound();

        $this->get('/about')
            ->assertOk()
            ->assertSee('Who we are')
            ->assertDontSee('hreflang', false)
            ->assertDontSee('og:locale', false)
            ->assertDontSee('cms-languages', false);

        $this->get('/sitemap.xml')->assertDontSee('xhtml', false)->assertDontSee('/es', false);
        $this->get('/llms.txt')->assertDontSee('## Languages', false);
        $this->assertFalse(app(PageRegistry::class)->isReserved('es'));
        $this->assertSame(['default' => 'en', 'enabled' => ['en']], app(PortalSummary::class)->build()['locales']);
    }

    public function test_the_portal_is_told_which_languages_the_site_speaks(): void
    {
        $this->assertSame(['default' => 'en', 'enabled' => ['en', 'es']], app(PortalSummary::class)->build()['locales']);
    }

    /**
     * @param  array<array-key, mixed>|string  $words
     */
    private function translateAndPublish(string $key, array|string $words): void
    {
        app(Translations::class)->store($key, 'es', $words, machine: false);
        app(PublishSiteContent::class)->handle();
    }
}
