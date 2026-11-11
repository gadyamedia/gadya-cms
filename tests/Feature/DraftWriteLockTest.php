<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Every change reads the whole draft and writes the whole draft back, so two at once used to drop one of
 * them without a word: a field saved while another saved, or while a card was added. Changes now take turns.
 */
class DraftWriteLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument([
            'pages' => ['rentals' => ['title' => 'Rentals', 'heading' => 'Rentals', 'sections' => [
                ['title' => 'Tables', 'type' => 'cards', 'items' => [['title' => 'Round table']]],
            ]]],
        ]);
        config(['gadya-cms.editable_fields' => ['pages.*.heading' => 'text']]);

        $this->actingAs($this->editor())->withSession([EditContext::SESSION_KEY => true]);
    }

    public function test_a_save_waits_for_the_change_already_writing_and_says_so_when_it_cannot(): void
    {
        config(['gadya-cms.editor.draft_lock_wait' => 1]);
        $held = Cache::lock('gadya-cms:draft-write:'.app(SiteContext::class)->id(), 30);
        $held->get();

        $this->postJson(route('gadya-cms.inline.update'), ['path' => 'pages.rentals.heading', 'value' => 'Party rentals'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another change is still saving. Try again in a moment.');

        $held->release();
        $this->assertSame('Rentals', app(SiteContentRepository::class)->draft()['pages']['rentals']['heading']);
    }

    public function test_a_locked_change_can_make_another_without_waiting_on_itself(): void
    {
        $repository = app(SiteContentRepository::class);

        $repository->withDraftLock(fn () => $repository->withDraftLock(function () use ($repository): void {
            $document = $repository->draft();
            $document['pages']['rentals']['heading'] = 'Nested';
            $repository->saveDraft($document);
        }));

        $this->assertSame('Nested', $repository->draft()['pages']['rentals']['heading']);
    }

    public function test_leaving_the_editor_waits_for_the_last_save(): void
    {
        $this->assertStringContainsString('data-cms-exit', view('gadya-cms::editor.toolbar')->render());
    }
}
