<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Option;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Gadya\Cms\Transfer\SiteExporter;
use Gadya\Cms\Transfer\SiteImporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class TransferTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Storage::fake('public');
        $this->dir = storage_path('framework/testing/transfer');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function populate(): void
    {
        Post::factory()->published()->create(['slug' => 'moving-day', 'title' => 'Moving day']);
        Redirect::query()->create(['site_id' => app(SiteContext::class)->id(), 'from_path' => '/old', 'to_path' => '/about']);
        app(Options::class)->set('analytics.digest_recipients', ['a@example.com']);
        app(Options::class)->setSecret('ai.key', 'sk-secret');
        Media::factory()->create(['filename' => 'cake.webp', 'path' => 'site-media/cake.webp', 'thumbnail_path' => 'site-media/thumbnails/cake.webp', 'variants' => ['480' => 'site-media/variants/cake-480.webp']]);
        Storage::disk('public')->put('site-media/cake.webp', 'full');
        Storage::disk('public')->put('site-media/thumbnails/cake.webp', 'thumb');
        Storage::disk('public')->put('site-media/variants/cake-480.webp', 'small');
    }

    public function test_a_zip_carries_everything_but_secrets_and_imports_into_an_empty_site(): void
    {
        $this->populate();
        $zip = $this->dir.'/site.zip';

        $this->artisan('gadya-cms:export', ['--path' => $zip, '--with-media' => true])->assertSuccessful();
        $this->assertFileExists($zip);

        $json = (string) json_encode(app(SiteExporter::class)->toArray());
        $this->assertStringNotContainsString('sk-secret', $json);
        $this->assertStringNotContainsString('ai.key', $json);

        Page::query()->delete();
        Post::query()->forceDelete();
        Redirect::query()->delete();
        Media::query()->delete();
        Option::query()->delete();
        Storage::fake('public');
        app(SiteContentRepository::class)->flushPublishedCache();

        $this->artisan('gadya-cms:import', ['path' => $zip])
            ->expectsOutputToContain('Pages')
            ->assertSuccessful();

        $this->assertSame(4, Page::query()->count());
        $this->assertSame('Welcome', app(SiteContentRepository::class)->published()['pages']['home']['title']);
        $this->assertSame('Moving day', Post::query()->where('slug', 'moving-day')->value('title'));
        $this->get('/old')->assertRedirect('/about');
        $this->assertSame(['a@example.com'], app(Options::class)->get('analytics.digest_recipients'));
        $this->assertNull(app(Options::class)->getSecret('ai.key'), 'The key stays with the install it was typed into.');
        $this->assertSame(['480' => 'site-media/variants/cake-480.webp'], Media::query()->where('filename', 'cake.webp')->value('variants'));
        Storage::disk('public')->assertExists('site-media/cake.webp');
        Storage::disk('public')->assertExists('site-media/variants/cake-480.webp');
    }

    public function test_importing_again_updates_in_place_and_replace_removes_what_was_not_exported(): void
    {
        $file = $this->dir.'/site.json';
        $this->artisan('gadya-cms:export', ['--path' => $file])->assertSuccessful();

        Page::query()->where('slug', 'about')->update(['title' => 'Changed locally']);
        Page::factory()->create(['slug' => 'local-only']);

        app(SiteImporter::class)->fromFile($file);
        $this->assertSame('About us', Page::query()->where('slug', 'about')->value('title'));
        $this->assertTrue(Page::query()->where('slug', 'local-only')->exists());

        app(SiteImporter::class)->fromFile($file, replace: true);
        $this->assertFalse(Page::query()->where('slug', 'local-only')->exists());
        $this->assertSame(4, Page::query()->count());
    }

    public function test_photos_cannot_ride_in_a_json_file_and_a_foreign_format_is_refused(): void
    {
        $this->artisan('gadya-cms:export', ['--path' => $this->dir.'/site.json', '--with-media' => true])->assertFailed();

        File::put($this->dir.'/other.json', json_encode(['format' => 99]));
        $this->artisan('gadya-cms:import', ['path' => $this->dir.'/other.json'])->assertFailed();
    }
}
