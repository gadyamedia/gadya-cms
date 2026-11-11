<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Tests\TestCase;

/**
 * The editing lock never strands anyone: it stays with whoever is working, a colleague can publish their
 * changes for them on purpose, and anyone may take over a lock left behind.
 */
class EditingLockHandoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument(['pages' => ['about' => ['title' => 'About', 'heading' => 'Who we are']]]);
        config(['gadya-cms.editable_fields' => ['pages.*.heading' => 'text']]);
    }

    public function test_saving_keeps_the_lock_with_the_person_editing(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->withSession([EditContext::SESSION_KEY => true])
            ->postJson(route('gadya-cms.inline.update'), ['path' => 'pages.about.heading', 'value' => 'Our story'])
            ->assertOk();

        $this->assertTrue(app(EditingLock::class)->isHeldBy($editor));
    }

    public function test_publishing_while_someone_else_edits_needs_asking_on_purpose_and_then_puts_their_changes_live(): void
    {
        $nicole = $this->editor();
        $nicole->forceFill(['name' => 'Nicole'])->save();
        app(EditingLock::class)->acquire($nicole);
        $draft = app(SiteContentRepository::class)->draft();
        $draft['pages']['about']['heading'] = 'Holiday hours';
        app(SiteContentRepository::class)->saveDraft($draft);

        $this->actingAs($this->administrator())->withSession([EditContext::SESSION_KEY => true]);

        $this->post(route('gadya-cms.publish'))->assertSessionHasErrors('publish');
        $this->assertSame('Who we are', app(SiteContentRepository::class)->published()['pages']['about']['heading']);

        $this->post(route('gadya-cms.publish'), ['anyway' => 1])
            ->assertSessionHas('status', "Everyone's changes are now live, Nicole's included.");
        app(SiteContentRepository::class)->flushPublishedCache();
        $this->assertSame('Holiday hours', app(SiteContentRepository::class)->published()['pages']['about']['heading']);
    }

    public function test_a_lock_left_behind_can_be_taken_over(): void
    {
        app(EditingLock::class)->acquire($this->administrator());
        $editor = $this->editor();

        $this->actingAs($editor)->withSession([EditContext::SESSION_KEY => true])
            ->post(route('gadya-cms.edit-mode.take-over'))
            ->assertSessionHas('status', 'You are editing now.');

        $this->assertTrue(app(EditingLock::class)->isHeldBy($editor));
    }

    public function test_someone_else_editing_offers_take_over_and_publish_anyway(): void
    {
        app(EditingLock::class)->acquire($this->administrator());
        $this->actingAs($this->editor())->withSession([EditContext::SESSION_KEY => true]);
        app(EditContext::class)->boot();

        $html = view('gadya-cms::editor.toolbar')->render();

        $this->assertStringContainsString('Ada Admin is editing right now', $html);
        $this->assertStringContainsString('Take over editing', $html);
        $this->assertStringContainsString('Publish anyway', $html);
    }
}
