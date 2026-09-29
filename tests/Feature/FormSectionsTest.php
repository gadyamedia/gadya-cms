<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\PageTypes;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Filament\Resources\Pages\Pages\EditPage;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Tests\TestCase;
use Gadya\Cms\Upgrade\SectionLoops;
use Gadya\Cms\Upgrade\Steps\EnableFormSections;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * A client putting a form on a page herself: as a section, inside longer
 * text, and swapping it from the live editor - and a site being told, and
 * helped, to draw them.
 */
class FormSectionsTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        parent::setUp();

        $document = app(SiteContentRepository::class)->defaults();
        $document['pages']['about']['sections'] = [
            ['type' => 'cards', 'title' => 'What we do', 'items' => [['title' => 'Parties']]],
            ['type' => 'form', 'title' => 'Book a party', 'text' => 'Tell us the date.', 'form' => 'booking'],
        ];
        $this->publishDocument($document);

        Form::factory()->published()->create(['slug' => 'booking', 'title' => 'Booking']);
        Form::factory()->published()->create(['slug' => 'quote', 'title' => 'Quote']);

        $this->views = sys_get_temp_dir().'/gadya-views-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->views.'/pages');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->views);

        parent::tearDown();
    }

    private function loop(): string
    {
        return <<<'BLADE'
        @foreach ($page['sections'] as $index => $section)
            @cmsSection($section, $index)
            <section class="site-{{ $section['type'] }}">{{ $section['title'] }}</section>
        @endforeach
        BLADE;
    }

    public function test_every_page_offers_a_form_section(): void
    {
        config(['gadya-cms.pages.types.location' => ['label' => 'Location', 'section_types' => ['gallery']]]);

        $this->assertContains('form', app(PageTypes::class)->sectionTypesFor('location'));
        $this->assertContains('form', app(PageTypes::class)->sectionTypesFor(null));

        config(['gadya-cms.forms.builder.sections' => false]);
        $this->assertNotContains('form', app(PageTypes::class)->sectionTypesFor(null));
    }

    public function test_one_line_in_the_sites_loop_draws_form_sections_and_leaves_the_rest_alone(): void
    {
        $page = app(SiteContentRepository::class)->published()['pages']['about'];

        $html = Blade::render($this->loop(), ['page' => $page]);

        $this->assertStringContainsString('<section class="site-cards">What we do</section>', $html);
        $this->assertStringNotContainsString('site-form', $html, 'The site\'s own markup never sees a form section.');
        $this->assertStringContainsString('<h2 class="cms-section__title">Book a party</h2>', $html);
        $this->assertStringContainsString('Tell us the date.', $html);
        $this->assertStringContainsString('data-slug="booking"', $html);
    }

    public function test_a_form_section_whose_form_is_not_live_shows_a_visitor_nothing(): void
    {
        Form::query()->where('slug', 'booking')->update(['status' => Form::STATUS_DRAFT]);
        $page = app(SiteContentRepository::class)->published()['pages']['about'];

        $html = Blade::render($this->loop(), ['page' => $page]);

        $this->assertStringNotContainsString('Book a party', $html);
        $this->assertStringNotContainsString('site-form', $html);
    }

    public function test_a_form_can_be_written_into_longer_text(): void
    {
        $html = Blade::render('@cmsMarkdown($text)', ['text' => "Fill this in:\n\n[form:quote]\n\nThanks!"]);

        $this->assertStringContainsString('<p>Fill this in:</p>', $html);
        $this->assertStringContainsString('data-slug="quote"', $html);
        $this->assertStringNotContainsString('[form:quote]', $html);

        $this->assertStringNotContainsString('[form:', Blade::render('@cmsMarkdown($text)', ['text' => '[form:nothing-here]']));

        Form::query()->where('slug', 'quote')->update(['fields' => [
            ['type' => 'paragraph', 'text' => 'See [form:quote]'],
            ['type' => 'email', 'key' => 'email', 'label' => 'Email'],
        ]]);
        $this->assertStringContainsString('data-slug="quote"', Blade::render('@cmsMarkdown($text)', ['text' => '[form:quote]']), 'A form mentioning itself does not loop.');
    }

    public function test_an_editor_can_edit_or_swap_the_form_from_the_page(): void
    {
        $this->actingAs($this->editor());
        session([EditContext::SESSION_KEY => true]);
        $editor = app(EditContext::class);
        $editor->boot();
        $editor->for('pages.about');

        $page = app(SiteContentRepository::class)->draft()['pages']['about'];
        $html = Blade::render($this->loop(), ['page' => $page]);

        $this->assertStringContainsString('Edit this form', $html);
        $this->assertStringContainsString('data-cms-section="pages.about.sections.1"', $html);
        $this->assertStringContainsString('Choose another form', $html);
        $this->assertStringContainsString('data-cms-path="pages.about.sections.1.title"', $html);
        $this->assertStringNotContainsString('data-events=', $html, 'An editor is never counted.');

        $this->withSession([EditContext::SESSION_KEY => true])
            ->postJson(route('gadya-cms.structure.update'), ['operation' => 'choose-form', 'section_path' => 'pages.about.sections.1', 'form' => 'quote'])
            ->assertOk();

        $this->assertSame('quote', app(SiteContentRepository::class)->draft()['pages']['about']['sections'][1]['form']);
        $this->assertSame('booking', app(SiteContentRepository::class)->published()['pages']['about']['sections'][1]['form'], 'Live only once published.');

        $this->postJson(route('gadya-cms.structure.update'), ['operation' => 'choose-form', 'section_path' => 'pages.about.sections.0', 'form' => 'quote'])->assertStatus(422);

        Form::query()->where('slug', 'quote')->update(['status' => Form::STATUS_DRAFT]);
        $this->postJson(route('gadya-cms.structure.update'), ['operation' => 'choose-form', 'section_path' => 'pages.about.sections.1', 'form' => 'quote'])->assertStatus(422);
    }

    public function test_the_upgrade_adds_the_line_to_an_ordinary_loop_and_the_audit_follows(): void
    {
        $file = $this->views.'/pages/show.blade.php';
        file_put_contents($file, "<main>\n    @foreach (\$page['sections'] ?? [] as \$i => \$block)\n        <section>{{ \$block['title'] }}</section>\n    @endforeach\n</main>\n");

        $loops = new SectionLoops(new Filesystem);

        $this->assertSame([['file' => $file, 'line' => 2, 'section' => '$block', 'index' => '$i', 'wired' => false]], $loops->find($this->views));

        $this->assertSame([$file], $loops->wire($this->views));
        $this->assertStringContainsString("@foreach (\$page['sections'] ?? [] as \$i => \$block)\n        @cmsSection(\$block, \$i)\n        <section>", (string) file_get_contents($file));
        $this->assertTrue($loops->find($this->views)[0]['wired']);
        $this->assertSame([], $loops->wire($this->views), 'Running it again changes nothing.');
    }

    public function test_the_step_and_the_audit_on_a_site_without_the_line(): void
    {
        (new Filesystem)->ensureDirectoryExists(resource_path('views'));
        $template = resource_path('views/gadya-form-sections-test.blade.php');
        file_put_contents($template, "@foreach (\$page['sections'] as \$section)\n<p>{{ \$section['title'] }}</p>\n@endforeach\n");

        try {
            $check = collect(app(InstallAudit::class)->checks())->firstWhere('label', 'Form sections are drawn on the page (@cmsSection in the section loop)');
            $this->assertSame(InstallAudit::TODO, $check['status']);
            $this->assertStringContainsString('@cmsSection($section)', $check['fix']);

            $step = app(EnableFormSections::class);
            $this->assertSame('code', $step->phase());
            $this->assertTrue($step->shouldRun());
            $this->assertStringContainsString('Added @cmsSection', $step->run());
            $this->assertFalse($step->shouldRun());

            $check = collect(app(InstallAudit::class)->checks())->firstWhere('label', 'Form sections are drawn on the page (@cmsSection in the section loop)');
            $this->assertSame(InstallAudit::OK, $check['status']);
        } finally {
            @unlink($template);
        }
    }

    public function test_the_page_edit_screen_offers_the_forms_and_keeps_the_choice(): void
    {
        $page = Page::query()->where('slug', 'about')->sole();

        Livewire::actingAs($this->editor())
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertSee('Booking')
            ->call('save')
            ->assertHasNoFormErrors();

        $sections = app(SiteContentRepository::class)->draft()['pages']['about']['sections'];

        $this->assertSame('form', $sections[1]['type']);
        $this->assertSame('booking', $sections[1]['form']);
    }
}
