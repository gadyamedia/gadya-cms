<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\SiteMarkdown;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;

/**
 * A legal page or a policy needs a list, a bold phrase and a link, and the
 * editor only edits plain text - so pages like that used to stay in the
 * template, out of the client's reach. A Markdown field is hers: she
 * writes the source, the page shows it rendered, and nothing that looks
 * like HTML is ever trusted.
 */
class MarkdownFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gadya-cms.editable_fields' => [
            ...config('gadya-cms.editable_fields'),
            'pages.*.sections.*.body' => 'markdown',
        ]]);

        $this->publishDocument([
            'pages' => ['privacy' => [
                'title' => 'Privacy',
                'sections' => [['heading' => 'Your rights', 'body' => "We keep:\n\n- your **name**\n- your email\n\nSee [the portal](https://app.example.com)."]],
            ]],
        ]);
    }

    public function test_it_renders_lists_bold_and_links(): void
    {
        $html = (string) app(SiteMarkdown::class)->render("- your **name**\n\n[the portal](https://app.example.com)");

        $this->assertStringContainsString('<li>your <strong>name</strong></li>', $html);
        $this->assertStringContainsString('<a href="https://app.example.com">the portal</a>', $html);
    }

    public function test_nothing_that_looks_like_html_or_a_script_link_is_trusted(): void
    {
        $html = (string) app(SiteMarkdown::class)->render("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[click](javascript:alert(1))");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_the_directive_renders_and_empty_text_renders_nothing(): void
    {
        $this->assertStringContainsString('<strong>bold</strong>', Blade::render('@cmsMarkdown($text)', ['text' => '**bold**']));
        $this->assertSame('', Blade::render('@cmsMarkdown($text)', ['text' => null]));
    }

    public function test_an_editor_gets_the_source_on_the_element_so_a_save_never_flattens_it(): void
    {
        $this->actingAs($this->editor());
        session([EditContext::SESSION_KEY => true]);

        $editor = app(EditContext::class);
        $editor->boot();
        $editor->for('pages.privacy');

        $attributes = (string) $editor->attributes('sections.0.body', 'markdown');

        $this->assertStringContainsString('data-cms-type="markdown"', $attributes);
        $this->assertStringContainsString('data-cms-value="We keep:', $attributes);
        $this->assertStringContainsString('- your **name**', html_entity_decode($attributes));
    }

    public function test_a_visitor_never_sees_the_source(): void
    {
        $editor = app(EditContext::class);
        $editor->boot();
        $editor->for('pages.privacy');

        $this->assertSame('', (string) $editor->attributes('sections.0.body', 'markdown'));
    }

    public function test_saving_a_markdown_field_returns_the_page_as_visitors_will_see_it(): void
    {
        $this->actingAs($this->editor());

        $this->withSession([EditContext::SESSION_KEY => true])
            ->postJson(route('gadya-cms.inline.update'), [
                'path' => 'pages.privacy.sections.0.body',
                'value' => "- one\n- **two**",
            ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('html', "<ul>\n<li>one</li>\n<li><strong>two</strong></li>\n</ul>\n");

        $this->assertSame("- one\n- **two**", app(SiteContentRepository::class)->draft()['pages']['privacy']['sections'][0]['body'], 'The source is stored, not the HTML.');
    }

    public function test_saving_plain_text_answers_as_before(): void
    {
        $this->actingAs($this->editor());

        $response = $this->withSession([EditContext::SESSION_KEY => true])
            ->postJson(route('gadya-cms.inline.update'), [
                'path' => 'pages.privacy.title',
                'value' => 'Privacy policy',
            ])
            ->assertOk();

        $this->assertSame(['saved' => true], $response->json());
    }
}
