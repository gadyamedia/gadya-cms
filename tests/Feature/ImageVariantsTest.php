<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Jobs\ProcessMediaUpload;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\ImageCapabilities;
use Gadya\Cms\Support\Images;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class ImageVariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_an_upload_gets_a_variant_for_every_width_narrower_than_itself(): void
    {
        config(['gadya-cms.media.variants' => [480, 960, 1600]]);
        Storage::disk('local')->put('staging/wide.png', $this->photo(1200, 800));
        $media = Media::factory()->processing()->create(['filename' => 'wide.webp']);

        (new ProcessMediaUpload($media, 'local', 'staging/wide.png'))->handle(app(ImageCapabilities::class));

        $media->refresh();
        $this->assertSame([480, 960], array_keys($media->variants), 'No 1600px variant of a 1200px photo.');
        Storage::disk('public')->assertExists('site-media/variants/wide-480.webp');
        Storage::disk('public')->assertExists('site-media/variants/wide-960.webp');
        $this->assertSame('ready', $media->status);
    }

    public function test_a_template_can_ask_for_a_width_or_a_srcset(): void
    {
        Media::factory()->create([
            'filename' => 'hero.webp',
            'path' => 'site-media/hero.webp',
            'width' => 2400,
            'variants' => ['960' => 'site-media/variants/hero-960.webp', '480' => 'site-media/variants/hero-480.webp'],
        ]);
        Media::factory()->legacy()->create(['filename' => 'old.jpg']);

        $images = app(SiteImage::class);

        $this->assertStringEndsWith('/site-media/variants/hero-480.webp', $images->url('hero.webp', 400));
        $this->assertStringEndsWith('/site-media/variants/hero-960.webp', $images->url('hero.webp', 700));
        $this->assertStringEndsWith('/site-media/hero.webp', $images->url('hero.webp', 2000));
        $this->assertSame(
            '/storage/site-media/variants/hero-480.webp 480w, /storage/site-media/variants/hero-960.webp 960w, /storage/site-media/hero.webp 2400w',
            $images->srcset('hero.webp'),
        );
        $this->assertStringContainsString('images/site/old.jpg', $images->srcset('old.jpg'));
        $this->assertStringContainsString('480w', Blade::render("@siteSrcset('hero.webp')"));
    }

    public function test_the_backfill_command_writes_variants_for_older_uploads(): void
    {
        config(['gadya-cms.media.variants' => [480]]);
        Storage::disk('public')->put('site-media/older.webp', $this->photo(1000, 600));
        $media = Media::factory()->create(['filename' => 'older.webp', 'path' => 'site-media/older.webp', 'variants' => null]);

        $this->artisan('gadya-cms:media-variants')->expectsOutputToContain('1 photos')->assertSuccessful();

        $this->assertSame(['480' => 'site-media/variants/older-480.webp'], $media->fresh()->variants);
        Storage::disk('public')->assertExists('site-media/variants/older-480.webp');
    }

    public function test_a_legacy_photo_gets_variants_and_keeps_its_original_where_it_is(): void
    {
        config(['gadya-cms.media.variants' => [480]]);
        $original = public_path('images/site/Old Logo.PNG');
        @mkdir(dirname($original), 0755, true);
        file_put_contents($original, $this->photo(1000, 600));
        $media = Media::factory()->legacy()->create(['filename' => 'Old Logo.PNG', 'path' => 'images/site/Old Logo.PNG', 'disk' => 'public', 'variants' => null, 'width' => 1000]);

        try {
            $this->artisan('gadya-cms:media-variants')->expectsOutputToContain('1 photos')->assertSuccessful();
        } finally {
            @unlink($original);
        }

        $this->assertSame(['480' => 'site-media/variants/legacy-old-logo-480.webp'], $media->fresh()->variants);
        Storage::disk('public')->assertExists('site-media/variants/legacy-old-logo-480.webp');

        $images = app(SiteImage::class);
        $this->assertStringEndsWith('/site-media/variants/legacy-old-logo-480.webp', $images->url('Old Logo.PNG', 400));
        $this->assertStringEndsWith('images/site/Old Logo.PNG', $images->url('Old Logo.PNG'), 'The original stays where it was shipped.');
        $this->assertStringEndsWith('images/site/Old Logo.PNG 1000w', $images->srcset('Old Logo.PNG'));
        $this->assertStringContainsString('legacy-old-logo-480.webp 480w', $images->srcset('Old Logo.PNG'));
    }

    /**
     * A plain photo of the given size, as WebP, under Intervention 3 or 4.
     */
    private function photo(int $width, int $height): string
    {
        $manager = new ImageManager(app(ImageCapabilities::class)->driver());
        $image = method_exists($manager, 'createImage') ? $manager->createImage($width, $height) : $manager->create($width, $height);

        return app(Images::class)->webp($image->fill('#00aaff'), 82);
    }
}
