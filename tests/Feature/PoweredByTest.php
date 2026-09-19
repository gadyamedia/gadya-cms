<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Tests\TestCase;

class PoweredByTest extends TestCase
{
    public function test_every_panel_screen_says_what_powers_it_and_which_version(): void
    {
        $version = GadyaCmsPlugin::packageVersion();

        $this->assertNotNull($version);
        $this->assertStringStartsNotWith('v', $version);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Site powered with')
            ->assertSee('href="https://gadya.media"', false)
            ->assertSee(ctype_digit($version[0]) ? 'v'.$version : $version);

        $this->actingAs($this->editor())->get('/admin')->assertOk()->assertSee('gadya.media');
    }
}
