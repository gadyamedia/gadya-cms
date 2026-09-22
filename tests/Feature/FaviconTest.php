<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Brand\Favicon;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * Almost every site we take over arrives without a favicon, and nobody
 * asks for one: the client does not know the word. So the site draws its
 * own from the logo - and a client who has a proper one keeps it.
 */
class FaviconTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        config(['gadya-cms.seo.site_name' => 'Acme Dental']);
    }

    public function test_it_draws_an_icon_and_serves_it_at_the_addresses_browsers_ask_for(): void
    {
        $png = $this->get('/favicon-32.png')->assertOk();

        $this->assertSame('image/png', $png->headers->get('Content-Type'));

        $image = imagecreatefromstring($png->getContent());
        $this->assertNotFalse($image, 'What comes back has to be a real image.');
        $this->assertSame(32, imagesx($image));
        $this->assertSame(32, imagesy($image));

        $this->get('/apple-touch-icon.png')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_ico_is_a_real_ico_wrapping_the_png(): void
    {
        $response = $this->get('/favicon.ico')->assertOk();

        $binary = $response->getContent();

        $this->assertSame('image/x-icon', $response->headers->get('Content-Type'));
        /* Reserved 0, type 1 (icon), one image in the file. */
        $this->assertSame([0, 1, 1], array_values(unpack('vreserved/vtype/vcount', substr($binary, 0, 6))));
        $this->assertStringContainsString("\x89PNG", $binary, 'An .ico may carry a PNG, and this one does.');
    }

    public function test_a_site_with_no_logo_gets_its_initials_rather_than_nothing(): void
    {
        $described = app(Favicon::class)->describe();

        $this->assertTrue($described['drawn']);
        $this->assertSame('initials', $described['from']);
        $this->assertGreaterThan(0, $described['bytes']);

        /*
         * The assertion that matters: a flat coloured square passes every
         * other check here - it is a real PNG of the right size - and is
         * exactly what a failed draw produces.
         */
        $this->assertFalse($described['blank'], 'Something legible has to be on it.');
    }

    public function test_an_svg_logo_is_drawn_rather_than_producing_an_empty_square(): void
    {
        $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 72"><rect width="320" height="72" fill="#123456"/><circle cx="36" cy="36" r="24" fill="#ffffff"/></svg>';

        File::ensureDirectoryExists(public_path('images/site'));
        File::put(public_path('images/site/lockup.svg'), $logo);
        config(['gadya-cms.brand.logo' => 'lockup.svg', 'gadya-cms.brand.follow_site' => false]);

        try {
            $favicon = app(Favicon::class);

            $this->assertSame('logo', $favicon->describe()['from']);

            $png = $favicon->png(180);
            $this->assertFalse($favicon->looksBlank($png), 'A wordmark that cannot be rasterised must not silently become a blank square.');

            /*
             * The proof that the logo was composited rather than fallen
             * back from: the mark's own colour is in the icon.
             */
            $image = imagecreatefromstring($png);
            $found = false;

            for ($x = 0; $x < 180 && ! $found; $x += 2) {
                for ($y = 0; $y < 180; $y += 2) {
                    $rgb = imagecolorat($image, $x, $y);

                    if ((($rgb >> 16) & 0xFF) === 0x12 && (($rgb >> 8) & 0xFF) === 0x34 && ($rgb & 0xFF) === 0x56) {
                        $found = true;
                        break;
                    }
                }
            }

            $this->assertTrue($found, 'The logo\'s own colour should be in the icon, which means it was drawn there.');
        } finally {
            File::delete(public_path('images/site/lockup.svg'));
        }
    }

    public function test_a_wide_lockup_is_cropped_to_its_mark_and_keeps_its_own_background(): void
    {
        /* A white tile: a red mark on the left, the "wordmark" on the right. */
        $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 72">'
            .'<rect width="320" height="72" fill="#ffffff"/>'
            .'<circle cx="36" cy="36" r="20" fill="#cc0000"/>'
            .'<rect x="90" y="30" width="200" height="12" fill="#222222"/></svg>';

        File::ensureDirectoryExists(public_path('images/site'));
        File::put(public_path('images/site/lockup.svg'), $logo);
        config(['gadya-cms.brand.logo' => 'lockup.svg', 'gadya-cms.brand.follow_site' => false]);

        try {
            $image = imagecreatefromstring(app(Favicon::class)->png(180));

            $corner = imagecolorat($image, 2, 2);
            $this->assertSame(0xFFFFFF, $corner & 0xFFFFFF, 'The logo\'s own white tile fills the icon, so no square floats inside another.');

            $middle = imagecolorat($image, 90, 90);
            $this->assertSame(0xCC0000, $middle & 0xFFFFFF, 'The mark, not the wordmark, is what a 32-pixel square can show.');
        } finally {
            File::delete(public_path('images/site/lockup.svg'));
        }
    }

    public function test_the_head_asks_for_the_icon_and_the_manifest(): void
    {
        $tags = app(Favicon::class)->tags();

        $this->assertSame(['icon', 'icon', 'apple-touch-icon'], array_column($tags, 'rel'));

        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Acme Dental')
            ->assertJsonPath('icons.1.sizes', '512x512');
    }

    public function test_a_site_that_already_has_a_favicon_of_its_own_is_left_alone(): void
    {
        File::ensureDirectoryExists(public_path());
        File::put(public_path('favicon.ico'), 'a real icon the client made');

        try {
            $this->assertTrue(app(Favicon::class)->siteHasItsOwn());
            $this->assertSame([], app(Favicon::class)->tags(), 'Two different icons in one head is worse than none.');
        } finally {
            File::delete(public_path('favicon.ico'));
        }
    }

    public function test_laravels_empty_placeholder_is_not_mistaken_for_a_favicon_and_the_audit_says_to_delete_it(): void
    {
        File::ensureDirectoryExists(public_path());
        File::put(public_path('favicon.ico'), '');

        try {
            $favicon = app(Favicon::class);

            $this->assertFalse($favicon->siteHasItsOwn(), 'Zero bytes is no icon at all.');
            $this->assertTrue($favicon->emptyPlaceholder());
            $this->assertNotSame([], $favicon->tags(), 'The drawn icon is offered instead.');

            $check = collect(app(\Gadya\Cms\Support\InstallAudit::class)->checks())
                ->firstWhere('label', 'No empty public/favicon.ico hides the browser-tab icon');

            $this->assertSame('todo', $check['status']);
            $this->assertStringContainsString('Delete public/favicon.ico', $check['fix']);
        } finally {
            File::delete(public_path('favicon.ico'));
        }
    }

    public function test_a_real_svg_counts_as_the_sites_own(): void
    {
        File::ensureDirectoryExists(public_path());
        File::put(public_path('favicon.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');

        try {
            $this->assertTrue(app(Favicon::class)->siteHasItsOwn());
        } finally {
            File::delete(public_path('favicon.svg'));
        }
    }

    public function test_the_command_says_where_the_icon_came_from(): void
    {
        $this->artisan('gadya-cms:favicon')
            ->expectsOutputToContain('initials')
            ->assertSuccessful();
    }
}
