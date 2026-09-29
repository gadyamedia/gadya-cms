<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Ai\Agents\TranslationWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Localisation\TranslateContent;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A built form in Spanish: its questions, choices and words laid over the
 * English, like any page - while its machinery, and the answers the
 * client reads, stay in the site's own language.
 */
class FormTranslationsTest extends TestCase
{
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gadya-cms.locales.enabled' => ['en', 'es']]);
        $this->publishDocument();
        Notification::fake();

        $this->form = Form::factory()->published()->withFields([
            ['type' => 'short_text', 'key' => 'name', 'label' => 'Your name', 'required' => true],
            ['type' => 'select', 'key' => 'service', 'label' => 'What do you need?', 'required' => true, 'width' => 'half', 'options' => [
                ['key' => 'party', 'label' => 'A party'],
                ['key' => 'catering', 'label' => 'Catering'],
            ]],
            ['type' => 'consent', 'key' => 'ok', 'label' => 'You may email me about my enquiry.', 'required' => true],
        ])->create(['slug' => 'enquiry', 'title' => 'Enquiry', 'messages' => ['submit' => 'Send it']]);
    }

    private function spanish(): void
    {
        app(Translations::class)->store('form:'.$this->form->getKey(), 'es', [
            'title' => 'Consulta',
            'fields' => [
                ['key' => 'name', 'label' => 'Su nombre'],
                ['key' => 'service', 'label' => '¿Qué necesita?', 'options' => [['key' => 'party', 'label' => 'Una fiesta'], ['key' => 'catering', 'label' => 'Comida']]],
                ['key' => 'ok', 'label' => 'Pueden escribirme sobre mi consulta.'],
            ],
            'messages' => ['submit' => 'Enviar'],
        ], machine: false);
        app(Translations::class)->publish();
    }

    public function test_the_form_is_drawn_in_spanish_on_a_spanish_page_and_in_english_on_an_english_one(): void
    {
        $this->spanish();

        $this->get('/es/forms/enquiry')
            ->assertOk()
            ->assertSee('Su nombre')
            ->assertSee('Una fiesta')
            ->assertSee('Enviar')
            ->assertSee('Consulta')
            ->assertSee('name="service"', false);

        $this->get('/forms/enquiry')->assertOk()->assertSee('Your name')->assertSee('Send it')->assertDontSee('Su nombre');
    }

    public function test_answers_are_kept_in_the_sites_language_but_consent_as_the_visitor_read_it(): void
    {
        $this->spanish();

        $this->postJson('/es/cms/forms/enquiry', ['name' => 'Ana', 'service' => 'catering', 'ok' => '1'])->assertOk();

        $submission = FormSubmission::query()->sole();

        $this->assertSame('Catering', $submission->data['service']);
        $this->assertSame('What do you need?', $submission->fieldLabels()['service']);
        $this->assertSame('Pueden escribirme sobre mi consulta.', $submission->meta['consents']['ok']['text']);
        $this->assertSame('es', $submission->meta['locale']);
    }

    public function test_translate_the_whole_site_includes_forms_and_only_their_words(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);

        $sent = [];
        TranslationWriter::fake(function (string $prompt) use (&$sent): array {
            $items = json_decode(Str::after($prompt, "ITEMS:\n"), true)['items'];
            $sent = [...$sent, ...array_column($items, 'text')];

            return ['translations' => array_map(fn (array $item): array => ['id' => $item['id'], 'text' => 'ES '.$item['text']], $items)];
        });

        $content = app(TranslateContent::class);
        $key = 'form:'.$this->form->getKey();

        $this->assertSame('Form', $content->units()[$key]['kind']);
        $this->assertContains($key, $content->pending('es'));

        $content->translate($key, 'es');

        $this->assertContains('What do you need?', $sent);
        $this->assertContains('Catering', $sent);
        $this->assertNotContains('half', $sent, 'A field\'s width is not words.');
        $this->assertNotContains('catering', $sent, 'Nor is a choice\'s key.');

        $row = Translation::query()->where('key', $key)->sole();
        $this->assertTrue($row->needs_review);
        $this->assertSame('ES Catering', $row->draft['fields'][1]['options'][1]['label']);
        $this->assertSame('catering', $row->draft['fields'][1]['options'][1]['key']);
    }
}
