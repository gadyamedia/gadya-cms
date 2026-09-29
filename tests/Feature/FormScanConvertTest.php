<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\Builder\FormScanner;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

/**
 * Finding the forms a site already has, for an agent converting them, and
 * building a form from each without touching a template.
 */
class FormScanConvertTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        $this->appDir = sys_get_temp_dir().'/gadya-scan-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->appDir);
        copy(__DIR__.'/../Fixtures/forms/app/BookingForm.php.txt', $this->appDir.'/BookingForm.php');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->appDir);

        parent::tearDown();
    }

    public function test_it_finds_configured_forms_template_forms_and_livewire_forms(): void
    {
        $found = collect(app(FormScanner::class)->scan(__DIR__.'/../Fixtures/forms', $this->appDir));

        $contact = $found->firstWhere('kind', 'config');
        $this->assertSame('contact', $contact['name']);
        $this->assertSame(['required', 'email', 'max:255'], $contact['fields']['email']['rules']);
        $this->assertFalse($contact['builder']);

        $quote = $found->firstWhere('kind', 'blade');
        $this->assertSame('quote', $quote['name']);
        $this->assertStringEndsWith('quote.blade.php', $quote['file']);
        $this->assertSame(3, $quote['line']);
        $this->assertSame(['name', 'email', 'job', 'when', 'details', 'rooms'], array_keys($quote['fields']));
        $this->assertSame(['type' => 'short_text', 'label' => 'Your name', 'required' => true], $quote['fields']['name']);
        $this->assertSame('Email address', $quote['fields']['email']['label']);
        $this->assertSame('email', $quote['fields']['email']['type']);
        $this->assertSame([['key' => 'repair', 'label' => 'A repair'], ['key' => 'install', 'label' => 'A new install']], $quote['fields']['job']['options']);
        $this->assertSame('radio', $quote['fields']['when']['type']);
        $this->assertSame('As soon as possible', $quote['fields']['when']['options'][0]['label']);
        $this->assertSame('long_text', $quote['fields']['details']['type']);
        $this->assertSame('checkboxes', $quote['fields']['rooms']['type']);

        $livewire = $found->firstWhere('kind', 'livewire');
        $this->assertSame('booking', $livewire['name']);
        $this->assertSame(['required', 'date'], $livewire['fields']['party_date']['rules']);
    }

    public function test_the_scan_command_answers_in_json_for_an_agent(): void
    {
        Artisan::call('gadya-cms:forms:scan', ['--json' => true]);

        $json = json_decode(Artisan::output(), true);

        $this->assertSame('contact', $json['forms'][0]['name']);
        $this->assertSame('contact', $json['forms'][0]['suggested_slug']);
    }

    public function test_a_dry_run_builds_nothing_and_says_what_would_be_built(): void
    {
        Artisan::call('gadya-cms:forms:convert', ['form' => 'contact', '--dry-run' => true, '--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertFalse($json['created']);
        $this->assertSame('<x-gadya-cms::form form="contact" />', $json['replacement']);
        $this->assertSame(['name', 'email', 'phone', 'message'], array_column($json['fields'], 'key'));
        $this->assertDatabaseCount('gadyacms_forms', 0);
    }

    public function test_a_configured_form_is_built_as_a_draft_under_the_same_name(): void
    {
        $this->artisan('gadya-cms:forms:convert', ['form' => 'contact'])
            ->expectsOutputToContain('<x-gadya-cms::form form="contact" />')
            ->assertSuccessful();

        $form = Form::query()->sole();
        $this->assertSame('contact', $form->slug);
        $this->assertSame(Form::STATUS_DRAFT, $form->status);
        $this->assertSame('lead_form_submit', $form->setting('analytics_event'));

        $this->artisan('gadya-cms:forms:convert', ['form' => 'contact'])->assertFailed();
        $this->artisan('gadya-cms:forms:convert', ['form' => 'nothing'])->assertFailed();
    }

    public function test_a_template_form_is_built_from_its_markup_and_the_template_is_left_alone(): void
    {
        $file = realpath(__DIR__.'/../Fixtures/forms/quote.blade.php');
        $before = file_get_contents($file);

        $this->artisan('gadya-cms:forms:convert', ['form' => 'quote', '--from' => 'blade', '--file' => $file])->assertSuccessful();

        $form = Form::query()->where('slug', 'quote')->sole();
        $fields = collect($form->fields)->keyBy('key');

        $this->assertSame(['name', 'email', 'job', 'when', 'details', 'rooms'], $fields->keys()->all());
        $this->assertSame('select', $fields['job']['type']);
        $this->assertSame(['repair', 'install'], array_column($fields['job']['options'], 'key'));
        $this->assertSame('radio', $fields['when']['type']);
        $this->assertSame('checkboxes', $fields['rooms']['type']);
        $this->assertSame('Your name', $fields['name']['label']);
        $this->assertTrue($fields['name']['required']);
        $this->assertSame($before, file_get_contents($file));
    }

    public function test_a_template_field_the_configuration_never_kept_is_left_out_with_a_warning(): void
    {
        config(['gadya-cms.forms.forms.quote' => ['label' => 'Quote', 'fields' => ['name' => ['required'], 'email' => ['required', 'email']]]]);

        Artisan::call('gadya-cms:forms:convert', ['form' => 'quote', '--from' => 'blade', '--file' => __DIR__.'/../Fixtures/forms/quote.blade.php', '--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(['name', 'email'], array_column($json['fields'], 'key'));
        $this->assertSame('Your name', $json['fields'][0]['label']);
        $this->assertCount(4, $json['warnings']);
    }
}
