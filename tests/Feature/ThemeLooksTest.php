<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\ThemeLooks;
use Gadya\Cms\Filament\Pages\ThemeSettings;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

/**
 * Whole looks - a Christmas look for December - switched in one go, with the look she had kept as Everyday
 * so going back is one click too.
 */
class ThemeLooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['site.theme' => [
            'colors' => ['primary' => '#9f12c7', 'background' => '#fffdf3'],
            'fonts' => ['display' => 'Yeseva One', 'sans' => 'Lato'],
        ]]);
        config(['gadya-cms.theme_looks' => [
            'christmas' => ['label' => 'Christmas', 'colors' => ['primary' => '#c8102e'], 'fonts' => ['display' => 'Lobster']],
        ]]);

        $this->publishDocument();
        $this->actingAs($this->editor());
    }

    public function test_switching_to_a_look_puts_it_in_the_draft_and_keeps_her_own_as_everyday(): void
    {
        $looks = app(ThemeLooks::class);

        $looks->apply('christmas');

        $this->assertSame(
            ['colors' => ['primary' => '#c8102e', 'background' => '#fffdf3'], 'fonts' => ['display' => 'Lobster', 'sans' => 'Lato']],
            app(SiteContentRepository::class)->draft()['theme'],
        );
        $this->assertSame(['everyday', 'christmas'], array_keys($looks->all()));

        $looks->apply('everyday');

        $this->assertSame(['display' => 'Yeseva One', 'sans' => 'Lato'], app(SiteContentRepository::class)->draft()['theme']['fonts']);
        $this->assertSame('#9f12c7', app(SiteContentRepository::class)->draft()['theme']['colors']['primary']);
    }

    public function test_her_own_looks_are_kept_and_forgotten(): void
    {
        $looks = app(ThemeLooks::class);

        $key = $looks->save('Summer 2027', ['primary' => '#0ea5e9', 'background' => '#ffffff', 'unknown' => '#000000'], ['display' => 'Fredoka', 'sans' => 'Lato']);

        $this->assertSame('summer-2027', $key);
        $this->assertSame(['primary' => '#0ea5e9', 'background' => '#ffffff'], $looks->all()['summer-2027']['colors']);

        $looks->delete('summer-2027');

        $this->assertArrayNotHasKey('summer-2027', $looks->all());
    }

    public function test_the_live_site_keeps_its_look_until_she_publishes(): void
    {
        app(ThemeLooks::class)->apply('christmas');

        $this->assertSame('Yeseva One', app(SiteContentRepository::class)->published()['theme']['fonts']['display']);
    }

    public function test_look_and_feel_says_where_each_colour_shows_and_offers_the_looks(): void
    {
        config(['gadya-cms.theme_color_hints' => ['primary' => 'Buttons and the main headline.']]);

        Livewire::test(ThemeSettings::class)
            ->assertSee('Buttons and the main headline.')
            ->assertSee('Holiday & seasonal looks')
            ->assertSee('Christmas');
    }
}
