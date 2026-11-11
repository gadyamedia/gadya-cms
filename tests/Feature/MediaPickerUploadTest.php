<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Livewire\MediaPicker;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A photo uploaded from the page is wanted on the page there and then. It used to wait on the queue as
 * "processing", greyed out in the picker - for good, on a site with no worker running - so the client could
 * only ever choose photos that were already there.
 */
class MediaPickerUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->publishDocument([
            'pages' => ['about' => ['title' => 'About', 'hero_image' => 'old.jpg']],
        ]);
        config(['gadya-cms.editable_fields' => ['pages.*.hero_image' => 'image']]);

        $this->actingAs($this->editor());
    }

    public function test_an_upload_is_ready_at_once_and_goes_where_the_photo_was_being_chosen(): void
    {
        Livewire::test(MediaPicker::class)
            ->call('open', 'pages.about.hero_image')
            ->set('upload', UploadedFile::fake()->image('cake.jpg', 900, 600))
            ->assertHasNoErrors()
            ->assertDispatched('cms:image-selected');

        $media = Media::query()->sole();
        $this->assertTrue($media->isReady());
        $this->assertSame($media->filename, app(SiteContentRepository::class)->draft()['pages']['about']['hero_image']);
    }

    public function test_the_picker_keeps_checking_on_photos_still_getting_ready(): void
    {
        Media::factory()->processing()->create(['original_name' => 'big.jpg']);

        Livewire::test(MediaPicker::class)
            ->assertSee('Getting ready…')
            ->assertSeeHtml('wire:poll.4s');
    }
}
