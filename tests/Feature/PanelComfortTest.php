<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Facades\Filament;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Tests\TestCase;

class PanelComfortTest extends TestCase
{
    public function test_a_person_can_change_their_own_name_and_password(): void
    {
        $this->publishDocument();

        $this->assertTrue(Filament::getPanel('admin')->hasProfile());

        $this->actingAs($this->editor())
            ->get(Filament::getPanel('admin')->getProfileUrl())
            ->assertOk()
            ->assertSee('Password');
    }

    public function test_leaving_a_screen_with_unsaved_edits_asks_first(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->hasUnsavedChangesAlerts());
    }

    public function test_both_can_be_turned_off_by_a_panel_that_has_its_own(): void
    {
        $plugin = GadyaCmsPlugin::make()->profile(false)->unsavedChangesAlerts(false);

        $this->assertFalse($plugin->hasProfile());
        $this->assertFalse($plugin->warnsAboutUnsavedChanges());
    }
}
