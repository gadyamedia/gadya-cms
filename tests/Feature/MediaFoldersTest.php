<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Content\MediaUsage;
use Gadya\Cms\Filament\Resources\Media\Pages\ListMedia;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class MediaFoldersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Storage::fake('public');
    }

    public function test_photos_can_be_moved_into_a_folder_and_filtered_by_it(): void
    {
        $one = Media::factory()->create();
        $two = Media::factory()->create();
        $other = Media::factory()->create(['folder' => 'Decor']);

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->selectTableRecords([$one, $two])
            ->callAction(TestAction::make('move')->table()->bulk(), ['folder' => 'Brooklyn'])
            ->filterTable('folder', 'Brooklyn')
            ->assertCanSeeTableRecords([$one, $two])
            ->assertCanNotSeeTableRecords([$other]);

        $this->assertSame(['Brooklyn', 'Decor'], Media::folders());
    }

    public function test_tags_are_kept_on_the_photo(): void
    {
        $photo = Media::factory()->create();

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('edit')->table($photo), ['alt_text' => 'A cake', 'tags' => ['birthday', 'cake']]);

        $this->assertSame(['birthday', 'cake'], $photo->fresh()->tags);
    }

    public function test_bulk_delete_skips_photos_still_in_use_and_names_them(): void
    {
        $used = Media::factory()->create(['filename' => 'hero.webp', 'original_name' => 'hero.jpg']);
        $onArticle = Media::factory()->create(['filename' => 'cover.webp', 'original_name' => 'cover.jpg']);
        $unused = Media::factory()->create();
        Post::factory()->create(['image' => 'cover.webp', 'title' => 'The article']);

        $this->assertSame(['Article: The article'], app(MediaUsage::class)->pagesUsing('cover.webp'));

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->selectTableRecords([$used, $onArticle, $unused])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified('Some photos are still in use');

        $this->assertDatabaseHas('gadyacms_media', ['id' => $used->id]);
        $this->assertDatabaseHas('gadyacms_media', ['id' => $onArticle->id]);
        $this->assertDatabaseMissing('gadyacms_media', ['id' => $unused->id]);
    }
}
