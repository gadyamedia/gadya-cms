<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\Builder\ControllerReader;
use Gadya\Cms\Forms\Builder\ConvertConfigForm;
use Gadya\Cms\Forms\Builder\FormScanner;
use Gadya\Cms\Forms\Builder\RoutesFile;
use Gadya\Cms\Forms\Builder\TemplateValues;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Tests\Fixtures\InvokableContactController;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * Scanning and converting the forms of a real two-brand site in en, ru
 * and uk (trimmed and anonymised under Fixtures/two-brand-site), whose first
 * run found: an invokable controller not followed, required labels that
 * lost their translations or kept their "*", choices drawn in @foreach
 * loops turned into a text field, labels that swallowed a dropdown's
 * choices, and the consent wording, analytics event, thank-you and lead
 * emails left behind.
 */
class FormRealSiteConvertTest extends TestCase
{
    private string $root;

    private string $fixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->useLangPath(__DIR__.'/../Fixtures/two-brand-site/lang');
        $app['config']->set('services.lead_notifications.email', 'leads@example.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        $this->fixtures = (string) realpath(__DIR__.'/../Fixtures/two-brand-site');
        $this->root = sys_get_temp_dir().'/gadya-real-site-'.uniqid();
        $files = new Filesystem;

        foreach ([
            'app/Http/Controllers/ContactController.php.txt' => 'app/Http/Controllers/ContactController.php',
            'app/Http/Controllers/PageController.php.txt' => 'app/Http/Controllers/PageController.php',
            'routes/web.php.txt' => 'routes/web.php',
            'resources/views/pages/contact.blade.php' => 'resources/views/pages/contact.blade.php',
            'resources/views/avant/pages/contact.blade.php' => 'resources/views/avant/pages/contact.blade.php',
        ] as $from => $to) {
            $files->ensureDirectoryExists(dirname($this->root.'/'.$to));
            copy($this->fixtures.'/'.$from, $this->root.'/'.$to);
        }

        $files->ensureDirectoryExists($this->root.'/config');
        copy(__DIR__.'/../../config/gadya-cms.php', $this->root.'/config/gadya-cms.php');
        $this->app->useAppPath($this->root.'/app');
        $this->app->useConfigPath($this->root.'/config');

        /* The site's own data classes and helper, which the page's controller and template call. */
        foreach (['app/Data/SiteContent.php.txt', 'app/Data/LocalizedSiteContent.php.txt', 'app/Support/helpers.php.txt'] as $file) {
            require_once $this->fixtures.'/'.$file;
        }

        $this->registerSiteRoutes();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_scan_and_convert_carry_over_everything_on_the_first_brands_form(): void
    {
        $scanned = $this->scanned('pages/contact.blade.php');

        $this->assertSame(['route' => 'contact.submit'], $scanned['action'], 'locale_route() is a named route too.');
        $this->assertSame('App\Http\Controllers\ContactController@__invoke', $scanned['handler']['action']);
        $this->assertSame('leads', $scanned['suggested_destination']['key']);
        $this->assertSame('App\Models\Lead', $scanned['suggested_destination']['config']['model']);
        $this->assertSame([
            'site' => '@site',
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'preferred_location' => 'location',
            'service_interest' => 'service_interest',
            'message' => 'message',
            'source_path' => '@page_url',
            'attribution' => '@attribution',
            'privacy_consented_at' => '@consent.at',
            'privacy_policy_version' => '@consent.policy_version',
        ], $scanned['suggested_destination']['config']['map']);
        $this->assertSame(['event' => 'cta_click', 'source' => 'data-analytics on the send button'], $scanned['analytics']);
        $this->assertSame(['leads@example.test'], $scanned['handler']['notify']['emails']);
        $this->assertSame([], $scanned['unmapped']);

        $json = $this->convert('pages/contact.blade.php');
        $fields = collect($json['fields'])->keyBy('key');

        $this->assertSame(['name', 'email', 'service_interest', 'phone', 'location', 'message', 'privacy_consent'], $fields->keys()->all());
        $this->assertSame(['Name', 'Email', 'What can we help with?', 'Phone', 'Preferred location', 'Comments'], $fields->take(6)->pluck('label')->all());
        $this->assertSame([true, true, false, false, false, true], $fields->take(6)->pluck('required')->all());
        $this->assertSame(['Имя', 'Email', 'С чем нужна помощь?', 'Телефон', 'Удобный кабинет', 'Комментарии'], $this->labels($json, 'ru'));
        $this->assertSame(['Ім’я', 'Email', 'З чим потрібна допомога?', 'Телефон', 'Зручний кабінет', 'Коментарі'], $this->labels($json, 'uk'));

        $this->assertSame(['deep_tissue_massage' => 'Deep Tissue Massage', 'sports_massage' => 'Sports Massage', 'prenatal_massage' => 'Prenatal Massage'], $fields['service_interest']['options']);
        $this->assertSame(['Глубокотканный массаж', 'Спортивный массаж', 'Массаж для беременных'], $this->translatedOptions($json, 'ru', 'service_interest'));
        $this->assertSame(['Глибокотканинний масаж', 'Спортивний масаж', 'Масаж для вагітних'], $this->translatedOptions($json, 'uk', 'service_interest'));
        $this->assertSame('Пока не знаю', collect($json['translations']['ru']['fields'])->firstWhere('key', 'service_interest')['placeholder']);
        $this->assertSame(['northtown_nj' => 'Northtown, NJ', 'southside_ny' => 'Southside, NY', 'either' => 'Either'], $fields['location']['options']);
        $this->assertSame(['Northtown, NJ', 'Southside, NY', 'Любой'], $this->translatedOptions($json, 'ru', 'location'));
        $this->assertSame(['Northtown, NJ', 'Southside, NY', 'Будь-який'], $this->translatedOptions($json, 'uk', 'location'));

        $this->assertSame('consent', $fields['privacy_consent']['type']);
        $this->assertSame('I agree to the privacy policy and consent to being contacted about this request. I understand this form is not for emergencies or sensitive medical information.', $json['consent']['wording']['en']);
        $this->assertStringStartsWith('Я соглашаюсь с политикой конфиденциальности и даю согласие', $json['consent']['wording']['ru']);
        $this->assertStringStartsWith('Я погоджуюся з політикою конфіденційності і даю згоду', $json['consent']['wording']['uk']);
        $this->assertSame(['privacy.policy_url' => '/privacy'], $json['suggested_config']);

        $this->assertSame('cta_click', $json['settings']['analytics_event']);
        $this->assertSame(['leads@example.test'], $json['settings']['notify']);
        $this->assertSame([
            'en' => 'Thanks — your request is in. Alex will follow up within one business day.',
            'ru' => 'Спасибо — запрос получен. Алекс свяжется с вами в течение одного рабочего дня.',
            'uk' => 'Дякуємо — запит отримано. Алекс зв’яжеться з вами протягом одного робочого дня.',
        ], $json['success']);
        $this->assertSame('Отправить запрос', $json['translations']['ru']['messages']['submit']);
    }

    public function test_scan_and_convert_carry_over_everything_on_the_second_brands_form(): void
    {
        $scanned = $this->scanned('avant/pages/contact.blade.php');

        $this->assertSame('App\Http\Controllers\ContactController@__invoke', $scanned['handler']['action']);
        $this->assertSame('leads', $scanned['suggested_destination']['key']);
        $this->assertSame('Preferred location', $scanned['fields']['location']['label'], 'Never the words of its choices.');
        $this->assertSame(['northtown-nj', 'southside-ny', 'either'], array_column($scanned['fields']['location']['options'], 'key'));

        $json = $this->convert('avant/pages/contact.blade.php');
        $fields = collect($json['fields'])->keyBy('key');

        $this->assertSame(['Name', 'Email', 'Phone', 'Preferred location', 'What would you like help with?'], $fields->take(5)->pluck('label')->all(), 'No "*" left to show twice.');
        $this->assertSame([true, true, false, false, true], $fields->take(5)->pluck('required')->all());

        /* Words written out in English, found word for word in the lang files, bring their translations. */
        $this->assertSame(['Имя', 'Email', 'Телефон', 'Удобный кабинет'], $this->labels($json, 'ru'));
        $this->assertSame(['Ім’я', 'Email', 'Телефон', 'Зручний кабінет'], $this->labels($json, 'uk'));
        $this->assertContains(['text' => 'Name', 'key' => 'pages.contact.name'], $json['lang_matches'], 'The contact form\'s own group wins over the chat widget\'s "Name".');
        $this->assertSame(['Northtown, NJ', 'Southside, NY', 'Любой'], $this->translatedOptions($json, 'ru', 'location'));

        $this->assertSame('consent', $fields['privacy_consent']['type']);
        $this->assertStringStartsWith('I agree to the privacy policy and consent', $json['consent']['wording']['en']);
        $this->assertStringStartsWith('Я соглашаюсь с политикой конфиденциальности', $json['consent']['wording']['ru']);
        $this->assertStringStartsWith('Я погоджуюся з політикою конфіденційності', $json['consent']['wording']['uk']);
        $this->assertSame(['privacy.policy_url' => '/privacy'], $json['suggested_config']);

        /* This brand's own thank-you, not the other brand's. */
        $this->assertSame(['en' => 'Thanks — your request is in. The clinic will reach out within one business day.'], $json['success']);
        $this->assertSame(['leads@example.test'], $json['settings']['notify']);
        $this->assertNull($json['analytics'], 'This brand\'s page sends no analytics event of its own.');
        $this->assertNull($json['settings']['analytics_event']);
    }

    public function test_converting_builds_the_form_with_its_translations_emails_and_event(): void
    {
        $this->artisan('gadya-cms:forms:convert', ['form' => 'contact', '--from' => 'blade', '--file' => $this->root.'/resources/views/pages/contact.blade.php'])
            ->expectsOutputToContain('leads@example.test')
            ->expectsOutputToContain('cta_click')
            ->assertSuccessful();

        $form = Form::query()->where('slug', 'contact')->sole();

        $this->assertSame(['leads@example.test'], $form->setting('notify'));
        $this->assertSame('cta_click', $form->setting('analytics_event'));
        $this->assertSame('Thanks — your request is in. Alex will follow up within one business day.', $form->message('success'));

        $russian = Translation::query()->where('key', 'form:'.$form->getKey())->where('locale', 'ru')->sole()->published;
        $this->assertSame('Имя', collect($russian['fields'])->firstWhere('key', 'name')['label']);
        $this->assertSame(['Глубокотканный массаж', 'Спортивный массаж', 'Массаж для беременных'], array_column(collect($russian['fields'])->firstWhere('key', 'service_interest')['options'], 'label'));
    }

    public function test_an_invokable_controller_is_followed_however_the_route_names_it(): void
    {
        $reader = app(ControllerReader::class);

        foreach (['contact.submit', 'avant.contact.submit', 'localized.ru.contact.submit'] as $name) {
            $this->assertSame('App\Http\Controllers\ContactController@__invoke', $reader->resolve(['route' => $name], $this->root.'/app')['action'], $name);
        }

        /* A class Laravel can load: its action is kept as the class alone. */
        Route::post('invokable', InvokableContactController::class)->name('invokable.submit');
        app('router')->getRoutes()->refreshNameLookups();
        $resolved = $reader->resolve(['route' => 'invokable.submit']);
        $this->assertSame('__invoke', $resolved['method']);
        $this->assertStringEndsWith('InvokableContactController.php', $resolved['file']);

        $scanner = app(FormScanner::class);

        foreach ([
            '{{ action(App\Http\Controllers\ContactController::class) }}',
            '{{ action([App\Http\Controllers\ContactController::class]) }}',
            '{{ action(\'App\Http\Controllers\ContactController\') }}',
        ] as $action) {
            $this->assertSame(['controller' => 'App\Http\Controllers\ContactController@__invoke'], $scanner->actionOf('<form method="POST" action="'.e($action).'"></form>'), $action);
        }
    }

    public function test_a_route_the_router_does_not_have_is_found_by_name_in_the_routes_files(): void
    {
        $routes = app(RoutesFile::class);

        foreach (['contact.submit', 'avant.contact.submit', 'localized.uk.contact.submit'] as $name) {
            $found = $routes->find($name, $this->root.'/routes');

            $this->assertSame(['App\Http\Controllers\ContactController', '__invoke'], [$found['class'], $found['method']], $name);
        }

        $this->assertNull($routes->find('nothing.here', $this->root.'/routes'));

        file_put_contents($this->root.'/routes/forms.php', <<<'PHP'
            <?php

            use App\Http\Controllers\ContactController;
            use Illuminate\Support\Facades\Route;

            Route::post('/a', [ContactController::class])->name('a.submit');
            Route::post('/b', 'App\Http\Controllers\ContactController')->name('b.submit');
            Route::post('/c', [ContactController::class, '__invoke'])->name('c.submit');
            Route::post('/d', 'ContactController@__invoke')->name('d.submit');
            Route::group(['as' => 'grouped.', 'prefix' => 'g'], function () {
                Route::post('/e', ContactController::class)->name('e.submit');
            });
            PHP);

        foreach (['a.submit', 'b.submit', 'c.submit', 'd.submit', 'grouped.e.submit'] as $name) {
            $found = $routes->find($name, $this->root.'/routes');

            $this->assertSame(['App\Http\Controllers\ContactController', '__invoke'], [$found['class'], $found['method']], $name);
        }

        $resolved = app(ControllerReader::class)->resolve(['route' => 'localized.uk.contact.submit'], $this->root.'/app');
        $this->assertSame('App\Http\Controllers\ContactController@__invoke', $resolved['action']);
        $this->assertStringStartsWith('routes file', $resolved['found_by']);
    }

    public function test_a_required_mark_is_taken_off_a_label_in_every_way_it_is_written_and_makes_the_field_required(): void
    {
        $fields = app(FormScanner::class)->fieldsIn(<<<'BLADE'
            <form>
                <label for="a">{{ __('pages.contact.phone') }} *</label><input id="a" name="a">
                <label for="b">{{ __('pages.contact.phone') }} <span>*</span></label><input id="b" name="b">
                <label for="c">@lang('pages.contact.phone') <span class="required">*</span></label><input id="c" name="c">
                <label for="d">Your town (required)</label><input id="d" name="d">
                <label for="e">Your street <abbr title="required">*</abbr></label><input id="e" name="e">
                <label for="f">{{ __('pages.contact.phone') }}</label><input id="f" name="f">
            </form>
            BLADE);

        foreach (['a', 'b', 'c'] as $name) {
            $this->assertSame('pages.contact.phone', $fields[$name]['label_key'], $name);
            $this->assertTrue($fields[$name]['required'], $name);
        }

        $this->assertSame(['Your town', true], [$fields['d']['label'], $fields['d']['required']]);
        $this->assertSame(['Your street', true], [$fields['e']['label'], $fields['e']['required']]);
        $this->assertSame(['pages.contact.phone', false], [$fields['f']['label_key'], $fields['f']['required']], 'A label without a mark is as it was.');
    }

    public function test_choices_drawn_in_a_loop_are_read_from_wherever_the_loop_gets_them(): void
    {
        config(['site-forms.budgets' => ['small' => 'Under $1,000', 'large' => 'Over $1,000']]);

        [$form] = app(FormScanner::class)->formsIn(<<<'BLADE'
            @php $sizes = ['s' => 'Small', 'l' => 'Large']; @endphp
            <form method="POST" action="/quote">
                <label for="budget">Budget</label>
                <select id="budget" name="budget">
                    @foreach (config('site-forms.budgets') as $value => $words)
                        <option value="{{ $value }}">{{ $words }}</option>
                    @endforeach
                </select>
                <fieldset>
                    <legend>Size</legend>
                    @foreach ($sizes as $value => $words)
                        <label><input type="radio" name="size" value="{{ $value }}"> {{ $words }}</label>
                    @endforeach
                </fieldset>
                <fieldset>
                    <legend>{{ __('pages.contact.interest') }}</legend>
                    @foreach (\App\Data\SiteContent::services() as $service)
                        <label><input type="checkbox" name="services[]" value="{{ $service['slug'] }}"> {{ $service['name'] }}</label>
                    @endforeach
                </fieldset>
                <label for="extras">Extras</label>
                <select id="extras" name="extras">
                    <option value="none">None</option>
                    @foreach ($extras as $extra)
                        <option value="{{ $extra->id }}">{{ $extra->name }}</option>
                    @endforeach
                </select>
            </form>
            BLADE, $this->root.'/resources/views/pages/quote.blade.php');

        $fields = $form['fields'];

        $this->assertSame(['small' => 'Under $1,000', 'large' => 'Over $1,000'], array_column($fields['budget']['options'], 'label', 'key'));
        $this->assertSame('Budget', $fields['budget']['label']);
        $this->assertSame(['radio', 'Size'], [$fields['size']['type'], $fields['size']['label']]);
        $this->assertSame(['s' => 'Small', 'l' => 'Large'], array_column($fields['size']['options'], 'label', 'key'));
        $this->assertSame(['checkboxes', 'pages.contact.interest'], [$fields['services']['type'], $fields['services']['label_key']]);
        $this->assertSame(['deep-tissue-massage', 'sports-massage', 'prenatal-massage'], array_column($fields['services']['options'], 'key'));

        /* Nothing says what $extras is: the right kind of field, the choices that are written out, and a note. */
        $this->assertSame(['select', 'Extras', ['none' => 'None']], [$fields['extras']['type'], $fields['extras']['label'], array_column($fields['extras']['options'], 'label', 'key')]);
        $this->assertSame([['field' => 'extras', 'note' => 'options come from $extras in '.$this->root.'/resources/views/pages/quote.blade.php; fill them in (the choices written out in the template are kept).']], $form['unmapped']);
        $this->assertStringNotContainsString('$extras as', json_encode($fields));

        $convert = app(ConvertConfigForm::class)->plan('quote', $fields, withConfig: false);
        $this->assertSame('select', collect($convert['fields'])->firstWhere('key', 'extras')['type']);
    }

    public function test_an_unresolved_loop_still_makes_a_dropdown_never_a_text_field_labelled_with_the_loop(): void
    {
        [$form] = app(FormScanner::class)->formsIn(<<<'BLADE'
            <form method="POST" action="/contact">
                <label class="block">
                    <span>What can we help with?</span>
                    <select name="service">
                        @foreach($services as $service) <option value="{{ $service }}">{{ $service }}</option> @endforeach
                    </select>
                </label>
            </form>
            BLADE, null);

        $this->assertSame(['type' => 'select', 'label' => 'What can we help with?', 'required' => false, 'options_from' => '$services'], $form['fields']['service']);
        $this->assertSame('options come from $services in the template; fill them in.', $form['unmapped'][0]['note']);

        $plan = app(ConvertConfigForm::class)->plan('contact', $form['fields'], withConfig: false);
        $this->assertSame(['select', 'What can we help with?'], [$plan['fields'][0]['type'], $plan['fields'][0]['label']]);
    }

    public function test_working_out_a_templates_values_only_ever_reads(): void
    {
        $values = app(TemplateValues::class);

        $this->assertSame(['ok' => true, 'value' => 'leads@example.test'], $values->evaluate("config('services.lead_notifications.email')"));
        $this->assertSame('Deep Tissue Massage', $values->evaluate("\$service['name']", ['service' => ['name' => 'Deep Tissue Massage']])['value']);
        $this->assertSame(['en' => 'Name *', 'ru' => 'Имя *'], $values->inLocales("__('pages.contact.name')", ['en', 'ru']));

        foreach ([
            "system('ls')",
            "collect(['ls'])->map('system')",
            '$x = 1',
            '`ls`',
            'new \ArrayObject([])',
            "Lead::create(['name' => 'x'])",
            "\App\Data\SiteContent::services()[0]['slug'] . \$undefined",
            '(fn () => 1)()',
            "call_user_func('phpinfo')",
            '"{$x}"',
        ] as $expression) {
            $this->assertFalse($values->evaluate($expression, ['x' => 'y'])['ok'], $expression);
        }
    }

    public function test_the_analytics_event_is_read_from_the_templates_script_too(): void
    {
        $scanner = app(FormScanner::class);

        $this->assertSame(['event' => 'generate_lead', 'source' => 'gtag(\'event\') in the template\'s script'], $scanner->analyticsOf('<form></form>', "<script>gtag('event', 'page_view'); gtag('event', 'generate_lead', {form: 'contact'});</script>"));
        $this->assertSame('quote_sent', $scanner->analyticsOf('<form></form>', "<script>window.dataLayer.push({ event: 'quote_sent' });</script>")['event']);
        $this->assertSame(['event' => 'lead', 'source' => 'data-analytics-event on the <form>'], $scanner->analyticsOf('<form data-analytics-event="lead"></form>'));
    }

    /**
     * @return array<string, mixed>
     */
    private function scanned(string $template): array
    {
        $found = collect(app(FormScanner::class)->scan($this->root.'/resources/views', $this->root.'/app'));

        return $found->first(fn (array $form): bool => $form['kind'] === 'blade' && $form['file'] === $this->root.'/resources/views/'.$template);
    }

    /**
     * @return array<string, mixed>
     */
    private function convert(string $template): array
    {
        Artisan::call('gadya-cms:forms:convert', [
            'form' => 'contact',
            '--from' => 'blade',
            '--file' => $this->root.'/resources/views/'.$template,
            '--destination' => ['leads'],
            '--write-config' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        return json_decode(Artisan::output(), true);
    }

    /**
     * The translated labels of the questions before the consent box.
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function labels(array $json, string $locale): array
    {
        return collect($json['translations'][$locale]['fields'])->reject(fn (array $field): bool => $field['key'] === 'privacy_consent' || ! isset($field['label']))->pluck('label')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function translatedOptions(array $json, string $locale, string $field): array
    {
        return array_column(collect($json['translations'][$locale]['fields'])->firstWhere('key', $field)['options'], 'label');
    }

    /**
     * The site's routes as Laravel keeps them: `Route::post('/contact',
     * ContactController::class)` has the class alone as its controller.
     */
    private function registerSiteRoutes(): void
    {
        $invokable = function (string $uri, string $name): void {
            $route = Route::post($uri, 'App\Http\Controllers\ContactController@__invoke')->name($name);
            $route->setAction([...$route->getAction(), 'controller' => 'App\Http\Controllers\ContactController']);
        };

        $invokable('contact', 'contact.submit');
        $invokable('avant/contact', 'avant.contact.submit');
        $invokable('ru/kontakty/otpravit', 'localized.ru.contact.submit');
        Route::get('privacy', fn (): string => '')->name('privacy');
        Route::get('contact', fn (): string => '')->name('contact');
        Route::get('avant/contact', fn (): string => '')->name('avant.contact');
        app('router')->getRoutes()->refreshNameLookups();
    }
}
