<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Jobs\ProcessMediaUpload;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\ImageCapabilities;
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
        $manager = new ImageManager(app(ImageCapabilities::class)->driver());
        Storage::disk('local')->put('staging/wide.png', (string) $manager->create(1200, 800)->fill('#ff00aa')->toPng());
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
        $manager = new ImageManager(app(ImageCapabilities::class)->driver());
        Storage::disk('public')->put('site-media/older.webp', (string) $manager->create(1000, 600)->fill('#00aaff')->toWebp());
        $media = Media::factory()->create(['filename' => 'older.webp', 'path' => 'site-media/older.webp', 'variants' => null]);

        $this->artisan('gadya-cms:media-variants')->expectsOutputToContain('1 photos')->assertSuccessful();

        $this->assertSame(['480' => 'site-media/variants/older-480.webp'], $media->fresh()->variants);
        Storage::disk('public')->assertExists('site-media/variants/older-480.webp');
    }
}
