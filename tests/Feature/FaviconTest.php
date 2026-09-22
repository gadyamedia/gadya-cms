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

    public function test_the_command_says_where_the_icon_came_from(): void
    {
        $this->artisan('gadya-cms:favicon')
            ->expectsOutputToContain('initials')
            ->assertSuccessful();
    }
}
