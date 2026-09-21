<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Facades\Filament;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Tests\TestCase;

/**
 * A site that keeps its own panel chrome still gets the colours and faces
 * the CMS screens are drawn with. Without them the dashboard, the photo
 * library and the editor render with no surfaces and no type at all.
 */
class PanelWithoutBrandTest extends TestCase
{
    public function test_the_cms_screens_keep_their_colours_when_the_chrome_is_the_sites_own(): void
    {
        GadyaCmsPlugin::get()->brand(false);
        GadyaCmsPlugin::get()->register(Filament::getPanel('admin'));

        $this->actingAs($this->administrator())
            ->get('/admin')
            ->assertOk()
            ->assertSee('--gadya-cms-primary', false)
            ->assertSee('--gadya-dash-surface', false);
    }

    public function test_a_branded_panel_still_gets_them_too(): void
    {
        $this->actingAs($this->administrator())
            ->get('/admin')
            ->assertOk()
            ->assertSee('--gadya-cms-primary', false)
            ->assertSee('--gadya-cms-display-font', false);
    }
}
