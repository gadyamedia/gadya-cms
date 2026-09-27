<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Ai\Agents\TranslationWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Filament\Pages\Languages;
use Gadya\Cms\Filament\Resources\Pages\Pages\ListPages;
use Gadya\Cms\Jobs\TranslateSiteContent;
use Gadya\Cms\Localisation\TranslateContent;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Localisation\Translator;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Translating a whole site by hand is the reason most small businesses
 * never do it. The machine writes a first draft of every page into the
 * Spanish drafts - never onto the live site - and a person reads it
 * through before it is published. What must not change (the markup, the
 * phone number, the prices, the business's name) cannot be changed.
 */
class MachineTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gadya-cms.locales.enabled' => ['en', 'es'],
            'gadya-cms.brand.name' => 'Fun On Us',
        ]);

        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);

        $this->publishDocument();
    }

    public function test_a_page_is_translated_into_a_draft_marked_for_review_and_not_published(): void
    {
        $this->fakeSpanish();

        $result = app(TranslateContent::class)->translate('pages.home', 'es');

        $this->assertSame(0, $result['kept']);

        $row = Translation::query()->where('key', 'pages.home')->sole();
        $this->assertTrue($row->needs_review);
        $this->assertSame(Translation::SOURCE_MACHINE, $row->source);
        $this->assertNull($row->published);
        $this->assertSame('ES Welcome to the site', $row->draft['heading']);
        $this->assertSame('ES We throw them.', $row->draft['sections'][0]['items'][0]['text']);
        $this->assertArrayNotHasKey('hero_image', $row->draft, 'Photos are never sent for translation.');
        $this->assertArrayNotHasKey('type', $row->draft);

        $this->assertSame('Welcome to the site', app(SiteContentRepository::class)->publishedIn('es')['pages']['home']['heading']);

        app(PublishSiteContent::class)->handle();
        $this->assertNull($row->fresh()->published, 'Publishing leaves an unread machine translation alone.');
    }

    public function test_markup_links_phone_numbers_prices_and_the_business_name_come_back_exactly(): void
    {
        $this->fakeSpanish();

        $original = '<p>Call <strong>Fun On Us</strong> on (555) 010-2030 or see <a href="/pricing">our prices</a> - parties from $249.99. Email hello@funonus.test or visit https://funonus.test/book.</p>';

        $translated = app(Translator::class)->translate(['body' => $original], 'es')['body'];

        $this->assertStringStartsWith('ES <p>Call <strong>Fun On Us</strong> on (555) 010-2030 or see <a href="/pricing">', $translated);
        $this->assertStringContainsString('$249.99', $translated);
        $this->assertStringContainsString('hello@funonus.test', $translated);
        $this->assertStringContainsString('https://funonus.test/book', $translated);

        TranslationWriter::assertPrompted(function ($prompt): bool {
            return ! str_contains($prompt->prompt, 'Fun On Us')
                && ! str_contains($prompt->prompt, '010-2030')
                && ! str_contains($prompt->prompt, '<strong>')
                && ! str_contains($prompt->prompt, '249.99');
        });
    }

    public function test_a_piece_that_comes_back_with_a_marker_missing_keeps_its_original_words(): void
    {
        TranslationWriter::fake(fn (string $prompt): array => ['translations' => array_map(
            fn (array $item): array => ['id' => $item['id'], 'text' => 'Llame hoy'],
            $this->items($prompt),
        )]);

        $translated = app(Translator::class)->translate(['cta' => 'Call (555) 010-2030 today', 'plain' => 'Book now'], 'es');

        $this->assertSame(['plain' => 'Llame hoy'], $translated, 'The phone number was lost, so that piece is thrown away.');
    }

    public function test_the_glossary_is_never_translated(): void
    {
        $this->fakeSpanish();
        app(Translator::class)->saveGlossary(['Bounce Castle Deluxe']);

        app(Translator::class)->translate(['text' => 'Hire the Bounce Castle Deluxe for the day'], 'es');

        TranslationWriter::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, 'Bounce Castle Deluxe'));
        $this->assertContains('Bounce Castle Deluxe', app(Translator::class)->glossary());
    }

    public function test_an_article_is_translated_and_reads_in_spanish_once_reviewed_and_published(): void
    {
        $this->fakeSpanish();
        $post = Post::factory()->published()->create([
            'slug' => 'foam-parties',
            'title' => 'Foam parties explained',
            'content' => '<h2>What happens</h2><p>Foam.</p>',
            'faq' => [['question' => 'Is it messy?', 'answer' => 'Gloriously.']],
        ]);
        $key = 'post:'.$post->id;

        app(TranslateContent::class)->translate($key, 'es');
        app(TranslateContent::class)->approve($key, 'es', $this->editor());
        app(PublishSiteContent::class)->handle();

        $this->get('/es/blog/foam-parties')
            ->assertOk()
            ->assertSee('ES Foam parties explained')
            ->assertSee('ES <h2>What happens</h2><p>Foam.</p>', false)
            ->assertSee('ES Is it messy?');

        $this->assertSame('Foam parties explained', $post->fresh()->title);
    }

    public function test_changing_the_original_marks_its_translation_out_of_date(): void
    {
        $this->fakeSpanish();
        $content = app(TranslateContent::class);

        $content->translate('pages.about', 'es');
        $this->assertSame(TranslateContent::STATUS_REVIEW, $content->status('pages.about', 'es'));

        $content->approve('pages.about', 'es');
        $this->assertSame(TranslateContent::STATUS_TRANSLATED, $content->status('pages.about', 'es'));

        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about']['heading'] = 'Twenty years of parties';
        $repository->saveDraft($document);
        app(Translations::class)->forget();

        $this->assertSame(TranslateContent::STATUS_OUTDATED, $content->status('pages.about', 'es'));
        $this->assertContains('pages.about', $content->pending('es'));
    }

    public function test_translating_the_whole_site_is_queued_a_few_pieces_at_a_time(): void
    {
        Queue::fake();
        config(['gadya-cms.locales.chunk' => 2]);

        $pending = app(TranslateContent::class)->pending('es');
        $queued = app(TranslateContent::class)->queue($pending, 'es');

        $this->assertSame(count($pending), $queued);
        $this->assertContains('pages.home', $pending);
        $this->assertContains('nav', $pending);
        $this->assertNotContains('theme', $pending);
        Queue::assertPushed(TranslateSiteContent::class, (int) ceil(count($pending) / 2));
        Queue::assertPushed(TranslateSiteContent::class, fn (TranslateSiteContent $job): bool => count($job->keys) <= 2 && $job->locale === 'es');
    }

    public function test_the_languages_screen_translates_the_site_and_marks_a_translation_reviewed(): void
    {
        $this->fakeSpanish();

        Livewire::actingAs($this->editor())
            ->test(Languages::class)
            ->assertSee('Not translated')
            ->callAction('translateSite')
            ->assertNotified();

        $this->assertSame(TranslateContent::STATUS_REVIEW, app(TranslateContent::class)->status('pages.about', 'es'));

        Livewire::actingAs($this->editor())
            ->test(Languages::class)
            ->assertSee('Machine translated - to review')
            ->callAction('approve', arguments: ['key' => 'pages.about'])
            ->assertNotified('Reviewed');

        app(Translations::class)->forget();
        $this->assertSame(TranslateContent::STATUS_TRANSLATED, app(TranslateContent::class)->status('pages.about', 'es'));
    }

    public function test_the_glossary_is_kept_from_the_languages_screen(): void
    {
        Livewire::actingAs($this->editor())
            ->test(Languages::class)
            ->fillForm(['glossary' => ['Bounce Castle Deluxe']])
            ->call('saveGlossary')
            ->assertNotified('Saved');

        $this->assertSame(['Bounce Castle Deluxe'], app(Translator::class)->glossaryOption());
    }

    public function test_the_languages_screen_is_not_there_on_a_site_with_one_language(): void
    {
        config(['gadya-cms.locales.enabled' => ['en']]);

        $this->actingAs($this->editor())->get(Languages::getUrl())->assertForbidden();
    }

    public function test_a_page_is_translated_from_the_pages_list(): void
    {
        $this->fakeSpanish();
        $page = Page::query()->where('slug', 'about')->sole();

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->callTableAction('translate', $page)
            ->assertNotified('Translating into Spanish');

        $this->assertSame('ES Who we are', Translation::query()->where('key', 'pages.about')->sole()->draft['heading']);
    }

    public function test_the_editor_on_a_spanish_page_can_translate_it_and_mark_it_reviewed(): void
    {
        $this->fakeSpanish();
        $editor = $this->editor();

        $this->actingAs($editor)
            ->withSession([EditContext::SESSION_KEY => true])
            ->post('/es/cms/translations/translate', ['key' => 'pages.about'])
            ->assertRedirect();

        $row = Translation::query()->where('key', 'pages.about')->sole();
        $this->assertTrue($row->needs_review);

        $this->actingAs($editor)
            ->withSession([EditContext::SESSION_KEY => true])
            ->post('/es/cms/translations/review', ['key' => 'pages.about'])
            ->assertRedirect();

        $this->assertFalse($row->fresh()->needs_review);
        $this->assertSame($editor->id, $row->fresh()->reviewed_by);
    }

    public function test_the_editor_endpoints_only_work_in_another_language(): void
    {
        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->post('/cms/translations/translate', ['key' => 'pages.about'])
            ->assertNotFound();
    }

    public function test_a_site_without_its_own_key_translates_through_gadya(): void
    {
        app(AiSettings::class)->forgetKey();
        Connection::query()->create([
            'site_id' => 7,
            'portal_url' => 'https://portal.test',
            'secret' => 'shhh',
            'site_name' => 'Fun On Us',
        ]);
        Http::fake(['portal.test/*' => Http::response(['text' => json_encode(['translations' => [['id' => '0', 'text' => 'Reserve ahora']]])])]);

        $this->assertSame(['cta' => 'Reserve ahora'], app(Translator::class)->translate(['cta' => 'Book now'], 'es'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.test/api/connect/v1/ai');
    }

    /**
     * A model that answers every item with "ES " in front, keeping its markers.
     */
    private function fakeSpanish(): void
    {
        TranslationWriter::fake(fn (string $prompt): array => ['translations' => array_map(
            fn (array $item): array => ['id' => $item['id'], 'text' => 'ES '.$item['text']],
            $this->items($prompt),
        )]);
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    private function items(string $prompt): array
    {
        return json_decode(Str::after($prompt, "ITEMS:\n"), true)['items'];
    }
}
