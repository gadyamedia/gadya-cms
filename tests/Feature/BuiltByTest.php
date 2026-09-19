<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Brand\ColorFilter;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;

class BuiltByTest extends TestCase
{
    public function test_the_badge_wears_the_sites_ink_at_the_end_of_the_footer(): void
    {
        config(['gadya-cms.brand.ink' => '#29376A']);

        $html = Blade::render('@gadyaBuiltBy');

        $this->assertStringContainsString('src="https://gadya.media/brand/built-by.v1.js?v=4"', $html);
        $this->assertStringContainsString('<gadya-built-by color="#29376a"', $html);
        $this->assertStringContainsString('filter="brightness(0) saturate(100%) invert(', $html);
        $this->assertStringContainsString('justify-content:flex-end', $html);
    }

    public function test_a_site_can_name_its_own_colour_and_placement_or_turn_it_off(): void
    {
        $html = Blade::render("@gadyaBuiltBy(['color' => '#fff', 'align' => 'center', 'logo_height' => 20])");

        $this->assertStringContainsString('color="#ffffff"', $html);
        $this->assertStringContainsString('justify-content:center', $html);
        $this->assertStringContainsString('logo-height="20"', $html);

        config(['gadya-cms.built_by.enabled' => false]);

        $this->assertSame('', trim(Blade::render('@gadyaBuiltBy')));
    }

    public function test_an_ink_that_is_not_a_hex_colour_falls_back_to_gadya_navy(): void
    {
        config(['gadya-cms.brand.ink' => 'rebeccapurple']);

        $this->assertStringContainsString('color="#29376a"', Blade::render('@gadyaBuiltBy'));
    }

    public function test_the_filter_turns_black_into_the_colour_asked_for(): void
    {
        foreach (['#29376a', '#b610cc', '#000000', '#ffffff', '#0f766e'] as $hex) {
            $solution = (new ColorFilter($hex))->solve();

            $this->assertLessThan(5, $solution['loss'], "{$hex} is matched closely enough to read as the same colour.");
            $this->assertStringStartsWith('brightness(0) saturate(100%) invert(', $solution['filter']);
        }

        $this->assertSame(ColorFilter::for('#29376A'), ColorFilter::for('29376a'), 'The same colour always gives the same filter.');

        $this->expectException(InvalidArgumentException::class);
        ColorFilter::normalise('not-a-colour');
    }
}
