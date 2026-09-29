<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/**
 * A built form on a site that chooses its language itself - from the
 * session, or a prefix of its own - rather than through the CMS's
 * languages: drawn, checked and answered in whatever the app says.
 */
class FormLocaleTest extends TestCase
{
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();

        $this->form = Form::factory()->published()->withSettings([
            'localised' => [
                ['locale' => 'ru', 'success' => 'Спасибо! Мы скоро ответим.', 'redirect' => '/ru/spasibo'],
            ],
        ])->withFields([
            ['type' => 'short_text', 'key' => 'name', 'label' => 'Your name', 'required' => true],
            ['type' => 'select', 'key' => 'service', 'label' => 'What do you need?', 'required' => true, 'options' => [
                ['key' => 'design', 'label' => 'A design'],
                ['key' => 'build', 'label' => 'Building work'],
            ]],
        ])->create(['slug' => 'enquiry', 'title' => 'Enquiry', 'messages' => ['success' => 'Thank you.', 'submit' => 'Send']]);

        foreach (['ru' => ['Ваше имя', 'Строительные работы', 'Отправить'], 'uk' => ['Ваше ім’я', 'Будівельні роботи', 'Надіслати']] as $locale => [$name, $build, $submit]) {
            app(Translations::class)->store('form:'.$this->form->getKey(), $locale, [
                'fields' => [
                    ['key' => 'name', 'label' => $name],
                    ['key' => 'service', 'options' => [['key' => 'build', 'label' => $build]]],
                ],
                'messages' => ['submit' => $submit],
            ], machine: false);
        }

        app(Translations::class)->publish();

        /* The site's own way of choosing the language: a prefix of its own routes. */
        Route::middleware('web')->get('{locale}/kontakty', function (string $locale) {
            App::setLocale($locale);

            return Blade::render('<x-gadya-cms::form form="enquiry" />');
        })->where('locale', 'en|ru|uk');
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function answers(array $answers = []): array
    {
        $this->travel(-10)->seconds();
        $seal = app(SpamGuard::class)->seal('enquiry');
        $this->travelBack();

        return ['_t' => $seal, '_path' => '/ru/kontakty', 'name' => 'Анна', 'service' => 'build', ...$answers];
    }

    public function test_the_form_is_drawn_in_the_language_the_app_chose(): void
    {
        $this->get('/ru/kontakty')
            ->assertOk()
            ->assertSee('Ваше имя')
            ->assertSee('Строительные работы')
            ->assertSee('A design', false)
            ->assertSee('Отправить')
            ->assertSee('name="_locale" value="ru"', false)
            ->assertSee('data-redirect="/ru/spasibo"', false);

        $this->get('/uk/kontakty')->assertOk()->assertSee('Ваше ім’я')->assertSee('Надіслати');
        $this->get('/en/kontakty')->assertOk()->assertSee('Your name')->assertDontSee('Ваше имя');
    }

    public function test_it_is_answered_in_the_language_it_was_drawn_in(): void
    {
        $this->postJson('/cms/forms/enquiry', $this->answers(['_locale' => 'ru']))
            ->assertOk()
            ->assertJson(['message' => 'Спасибо! Мы скоро ответим.', 'redirect' => '/ru/spasibo']);

        $submission = FormSubmission::query()->sole();
        $this->assertSame('Building work', $submission->data['service'], 'Answers are kept in the site\'s own words.');
        $this->assertSame('ru', $submission->meta['locale']);
        $this->assertSame('ru', $submission->meta['attribution']['locale']);
        $this->assertSame('Your name', $submission->fieldLabels()['name']);
    }

    public function test_without_javascript_the_thank_you_is_flashed_in_that_language_on_its_own_page(): void
    {
        $this->post('/cms/forms/enquiry', $this->answers(['_locale' => 'ru']))
            ->assertRedirect('/ru/spasibo')
            ->assertSessionHas('gadya-cms.form.enquiry', 'Спасибо! Мы скоро ответим.');

        $this->post('/cms/forms/enquiry', $this->answers(['_locale' => 'uk']), ['Referer' => 'http://localhost/uk/kontakty'])
            ->assertRedirect('/uk/kontakty')
            ->assertSessionHas('gadya-cms.form.enquiry', 'Thank you.');

        $this->assertSame(['ru', 'uk'], FormSubmission::query()->orderBy('id')->get()->pluck('meta.locale')->all(), 'A language only the form speaks is taken too.');
    }

    public function test_a_language_the_site_does_not_speak_is_not_taken_from_the_post(): void
    {
        $this->postJson('/cms/forms/enquiry', $this->answers(['_locale' => 'xx']))->assertOk()->assertJson(['message' => 'Thank you.']);
        $this->postJson('/cms/forms/enquiry', $this->answers(['_locale' => '../../etc']))->assertOk();

        $this->assertSame(['en', 'en'], FormSubmission::query()->orderBy('id')->get()->pluck('meta.locale')->all());
    }

    public function test_the_panel_never_sees_the_translation(): void
    {
        App::setLocale('ru');

        $this->assertSame('Your name', Form::query()->find($this->form->getKey())->fields[0]['label']);
        $this->assertSame('Ваше имя', Form::findLive('enquiry')->fields[0]['label']);
    }
}
