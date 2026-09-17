<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\Globals;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class GlobalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_the_configured_globals_are_edited_together_and_saved_to_the_draft(): void
    {
        config(['gadya-cms.globals' => [...config('gadya-cms.globals'), 'footer.tagline' => ['label' => 'Footer tagline', 'type' => 'textarea', 'group' => 'Footer']]]);

        Livewire::actingAs($this->editor())
            ->test(Globals::class)
            ->assertFormSet(['announcement' => 'Now booking summer parties'])
            ->assertSee('Footer tagline')
            ->fillForm(['announcement' => 'Closed for the holidays', 'footer.tagline' => 'See you soon', 'phone' => '(555) 000-0000'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved to your draft');

        $repository = app(SiteContentRepository::class);

        $this->assertSame('Closed for the holidays', $repository->draft()['announcement']);
        $this->assertSame('See you soon', $repository->draft()['footer']['tagline']);
        $this->assertSame('Now booking summer parties', $repository->published()['announcement'], 'Nothing is live until published.');
    }

    public function test_a_required_global_cannot_be_blanked(): void
    {
        Livewire::actingAs($this->editor())
            ->test(Globals::class)
            ->fillForm(['announcement' => ''])
            ->call('save')
            ->assertHasFormErrors(['announcement' => 'required']);
    }

    public function test_the_screen_carries_a_publish_button(): void
    {
        Livewire::actingAs($this->editor())
            ->test(Globals::class)
            ->assertActionVisible('publishChanges');
    }
}
