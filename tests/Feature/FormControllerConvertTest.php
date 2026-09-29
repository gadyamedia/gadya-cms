<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\Builder\ConfigWriter;
use Gadya\Cms\Forms\Builder\FormScanner;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Tests\Fixtures\Lead;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Converting a form that posts to the site's own controller - one that
 * saves a lead with its campaign and consent, fires an event and thanks
 * the visitor in their language - without losing any of it: the
 * controller is read, a destination suggested (and written to config
 * only when asked), and the words imported from the lang files in every
 * language.
 */
class FormControllerConvertTest extends TestCase
{
    private string $root;

    private string $template;

    private string $appPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->useLangPath(__DIR__.'/../Fixtures/lang');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        $files = new Filesystem;
        $this->root = sys_get_temp_dir().'/gadya-site-'.uniqid();
        $files->ensureDirectoryExists($this->root.'/app/Http/Controllers');
        $files->ensureDirectoryExists($this->root.'/app/Providers/Filament');
        $files->ensureDirectoryExists($this->root.'/config');
        copy(__DIR__.'/../Fixtures/site-forms/app/Http/Controllers/ContactController.php.txt', $this->root.'/app/Http/Controllers/ContactController.php');
        copy(__DIR__.'/../Fixtures/site-forms/app/Providers/Filament/AdminPanelProvider.php.txt', $this->root.'/app/Providers/Filament/AdminPanelProvider.php');
        copy(__DIR__.'/../../config/gadya-cms.php', $this->root.'/config/gadya-cms.php');

        $this->appPath = app_path();
        $this->app->useAppPath($this->root.'/app');
        $this->app->useConfigPath($this->root.'/config');
        $this->template = (string) realpath(__DIR__.'/../Fixtures/site-forms/views/contact.blade.php');

        Route::post('contact', 'App\Http\Controllers\ContactController@send')->name('contact.send');
        Route::post('booking', 'App\Http\Controllers\ContactController@book')->name('booking.store');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_scan_reads_the_controller_and_suggests_a_destination_that_does_the_same(): void
    {
        $found = collect(app(FormScanner::class)->scan(dirname($this->template), $this->root.'/app'));
        $contact = $found->firstWhere('kind', 'blade');

        $this->assertNull($contact['name']);
        $this->assertSame(['route' => 'contact.send'], $contact['action']);
        $this->assertSame('App\Http\Controllers\ContactController@send', $contact['handler']['action']);
        $this->assertSame(13, $contact['handler']['line']);
        $this->assertSame(Lead::class, $contact['handler']['writes'][0]['model']);
        $this->assertSame(['App\Events\LeadReceived'], $contact['handler']['events']);
        $this->assertSame(['back' => true, 'flash' => 'success', 'message_key' => 'contact.thanks'], $contact['handler']['redirect']);
        $this->assertTrue($contact['handler']['consent']);
        $this->assertTrue($contact['handler']['attribution']);
        $this->assertTrue($contact['handler']['emails']);
        $this->assertSame([], $contact['handler']['custom']);
        $this->assertSame('privacy.version', $contact['handler']['policy_version_config']);
        $this->assertContains('Creates a Lead ('.Lead::class.')', $contact['handler']['does']);

        $suggested = $contact['suggested_destination'];
        $this->assertSame('leads', $suggested['key']);
        $this->assertSame([
            'label' => 'Leads',
            'model' => Lead::class,
            'map' => [
                'name' => 'name',
                'email' => 'email',
                'topic' => 'topic',
                'message' => 'message',
                'brand' => '@site',
                'source' => '@utm_source',
                'landing_page' => '@landing_page',
                'locale' => '@locale',
                'consented_at' => '@consent.at',
                'privacy_version' => '@consent.policy_version',
            ],
            'defaults' => ['status' => 'new'],
        ], $suggested['config']);
        $this->assertSame(['score' => '$this->score($request)'], $suggested['unmapped']);

        $this->assertStringContainsString('->forms(false)', app(FormScanner::class)->notices($this->root.'/app')[0]);
    }

    public function test_a_controller_that_calls_another_service_is_flagged_for_a_person(): void
    {
        $booking = app(FormScanner::class)->handler(['action' => ['route' => 'booking.store'], 'fields' => ['name' => []]], $this->root.'/app');

        $this->assertSame(['calls another service over HTTP (Http::)'], $booking['handler']['custom']);
        $this->assertNull($booking['suggested_destination']);
        $this->assertTrue($booking['handler']['beyond_storing']);
    }

    public function test_the_scan_command_warns_when_the_panel_has_forms_switched_off(): void
    {
        Artisan::call('gadya-cms:forms:scan', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertStringContainsString('->forms(false)', $json['notices'][0]);
    }

    public function test_a_dry_run_shows_the_config_it_would_write_and_writes_nothing(): void
    {
        $before = file_get_contents($this->root.'/config/gadya-cms.php');

        Artisan::call('gadya-cms:forms:convert', [
            'form' => 'contact',
            '--from' => 'blade',
            '--file' => $this->template,
            '--destination' => ['leads'],
            '--write-config' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $json = json_decode(Artisan::output(), true);

        $this->assertFalse($json['created']);
        $this->assertFalse($json['config']['written']);
        $this->assertStringContainsString("+            'destinations' => [", $json['config']['diff']);
        $this->assertStringContainsString("+                'leads' => [", $json['config']['diff']);
        $this->assertStringContainsString("+                    'model' => \\Gadya\\Cms\\Tests\\Fixtures\\Lead::class,", $json['config']['diff']);
        $this->assertStringContainsString("'source' => '@utm_source',", $json['config']['diff']);
        $this->assertSame(['leads'], $json['settings']['destinations']);
        $this->assertSame(['name', 'email', 'topic', 'message', 'consent'], array_column($json['fields'], 'key'), 'The template decides the questions, not the configured contact form of the same name.');
        $this->assertSame($before, file_get_contents($this->root.'/config/gadya-cms.php'));
        $this->assertDatabaseCount('gadyacms_forms', 0);
    }

    public function test_converting_writes_the_destination_imports_every_language_and_chooses_it_for_the_form(): void
    {
        $this->artisan('gadya-cms:forms:convert', [
            'form' => 'contact',
            '--from' => 'blade',
            '--file' => $this->template,
            '--destination' => ['leads'],
            '--write-config' => true,
        ])->assertSuccessful();

        $written = require $this->root.'/config/gadya-cms.php';
        $this->assertSame(Lead::class, $written['forms']['builder']['destinations']['leads']['model']);
        $this->assertSame('@consent.policy_version', $written['forms']['builder']['destinations']['leads']['map']['privacy_version']);
        $this->assertSame(['status' => 'new'], $written['forms']['builder']['destinations']['leads']['defaults']);
        $this->assertSame('website', $written['forms']['honeypot'], 'The rest of the file is as it was.');
        $this->assertSame(3, $written['forms']['builder']['min_seconds']);

        $form = Form::query()->where('slug', 'contact')->sole();
        $fields = collect($form->fields)->keyBy('key');

        $this->assertSame(['leads'], $form->setting('destinations'));
        $this->assertSame('Your name', $fields['name']['label']);
        $this->assertSame('Anna Petrova', $fields['name']['placeholder']);
        $this->assertSame('Email address', $fields['email']['label'], '@lang() is read too.');
        $this->assertSame('Your message', $fields['message']['label'], 'So is trans() in an aria-label.');
        $this->assertSame(['A design', 'Building work'], array_column($fields['topic']['options'], 'label'), 'A JSON key is its own English.');
        $this->assertSame('consent', $fields['consent']['type']);
        $this->assertSame('I agree to the privacy policy.', $fields['consent']['label']);
        $this->assertSame('Send', $form->message('submit'));
        $this->assertSame('Thank you. We will be in touch.', $form->message('success'));

        $russian = Translation::query()->where('key', 'form:'.$form->getKey())->where('locale', 'ru')->sole();
        $this->assertFalse($russian->needs_review, 'The site\'s own words go live with the form.');
        $this->assertSame($russian->draft, $russian->published);
        $this->assertSame('Спасибо. Мы свяжемся с вами.', $russian->published['messages']['success']);

        $form->update(['status' => Form::STATUS_PUBLISHED]);

        /* Back to the real application, whose components the form is drawn with. */
        $this->app->useAppPath($this->appPath);

        App::setLocale('ru');
        $html = (string) Blade::render('<x-gadya-cms::form form="contact" />');
        $this->assertStringContainsString('Ваше имя', $html);
        $this->assertStringContainsString('Анна Петрова', $html);
        $this->assertStringContainsString('Строительные работы', $html);
        $this->assertStringContainsString('Отправить', $html);

        App::setLocale('uk');
        $html = (string) Blade::render('<x-gadya-cms::form form="contact" />');
        $this->assertStringContainsString('Ваше ім’я', $html);
        $this->assertStringContainsString('Будівельні роботи', $html);
        $this->assertStringContainsString('Your message', $html, 'A word Ukrainian does not have stays in English.');

        /* The check the skill asks for: a test enquiry lands in the inbox and in the site's own table. */
        App::setLocale('en');
        config([
            'gadya-cms.forms.builder.destinations' => $written['forms']['builder']['destinations'],
            'gadya-cms.privacy.policy_version' => '2026-03',
        ]);
        Notification::fake();

        $this->travel(-10)->seconds();
        $seal = app(SpamGuard::class)->seal('contact');
        $this->travelBack();

        $this->postJson('/cms/forms/contact', [
            '_t' => $seal,
            'name' => 'Anna',
            'email' => 'anna@example.com',
            'topic' => 'build',
            'consent' => '1',
            '_attribution' => ['utm_source' => 'google'],
        ])->assertOk();

        $lead = Lead::query()->sole();
        $this->assertSame(['Anna', 'Building work', 'google', 'new', '2026-03'], [$lead->name, $lead->topic, $lead->source, $lead->status, $lead->privacy_version]);
        $this->assertNotNull($lead->consented_at);
        $this->assertSame('Lead #'.$lead->getKey(), FormSubmission::query()->sole()->meta['destinations']['leads']['result']);

        $this->app->useAppPath($this->root.'/app');
        $this->artisan('gadya-cms:forms:convert', ['form' => 'contact', '--from' => 'blade', '--file' => $this->template, '--write-config' => true, '--dry-run' => true])
            ->expectsOutputToContain('already has the destination "leads"')
            ->assertSuccessful();
    }

    public function test_a_destination_that_does_not_exist_is_refused(): void
    {
        $this->artisan('gadya-cms:forms:convert', ['form' => 'contact', '--from' => 'blade', '--file' => $this->template, '--destination' => ['crm']])
            ->expectsOutputToContain('There is no destination called "crm"')
            ->assertFailed();

        $this->artisan('gadya-cms:forms:convert', ['form' => 'contact', '--write-config' => true])
            ->expectsOutputToContain('There is no destination to write')
            ->assertFailed();

        $this->assertDatabaseCount('gadyacms_forms', 0);
    }

    public function test_the_config_writer_adds_to_any_shape_of_file_and_never_twice(): void
    {
        $writer = app(ConfigWriter::class);
        $destination = ['label' => 'Leads', 'model' => Lead::class, 'map' => ['email' => 'email'], 'defaults' => []];
        $path = $this->root.'/config/gadya-cms.php';

        foreach ([
            "<?php\n\nreturn [\n    'brand' => ['name' => 'Aleksey'],\n];\n",
            "<?php\n\nreturn [\n    'forms' => [\n        'honeypot' => 'website', // ['not' => 'this']\n    ],\n];\n",
            "<?php\n\nreturn [\n    'forms' => [\n        'builder' => [\n            'destinations' => [\n                'crm' => 'App\\\\Forms\\\\Crm',\n            ],\n        ],\n    ],\n];\n",
            null,
        ] as $source) {
            $source === null ? @unlink($path) : file_put_contents($path, $source);

            $this->assertTrue($writer->write('leads', $destination));
            $this->assertFalse($writer->write('leads', $destination), 'A destination already there is left alone.');

            $config = require $path;
            $this->assertSame(Lead::class, $config['forms']['builder']['destinations']['leads']['model']);

            if ($source !== null && str_contains($source, 'honeypot')) {
                $this->assertSame('website', $config['forms']['honeypot']);
            }

            if ($source !== null && str_contains($source, 'crm')) {
                $this->assertSame('App\Forms\Crm', $config['forms']['builder']['destinations']['crm']);
            }
        }
    }
}
