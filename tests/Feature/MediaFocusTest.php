<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Ai\Agents\AltTextWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Filament\Resources\Media\Pages\ListMedia;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class MediaFocusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Storage::fake('public');
    }

    public function test_a_photo_keeps_the_part_that_matters_in_view_when_it_is_cropped(): void
    {
        Media::factory()->create(['filename' => 'plain.webp']);
        $framed = Media::factory()->create(['filename' => 'hero.webp', 'focal_x' => 50, 'focal_y' => 15]);

        $this->assertSame('object-position: 50% 50%;', app(SiteImage::class)->focus('plain.webp'), 'A photo nobody has framed sits where the browser would have put it.');
        $this->assertSame('object-position: 50% 15%;', app(SiteImage::class)->focus('hero.webp'));
        $this->assertSame('object-position: 50% 15%;', Blade::render("@siteFocus('hero.webp')"));
        $this->assertSame('top', $framed->focusPreset());
        $this->assertSame('centre', Media::factory()->make()->focusPreset());
    }

    public function test_a_photo_that_is_not_in_the_library_still_answers(): void
    {
        $this->assertSame('object-position: 50% 50%;', app(SiteImage::class)->focus('never-uploaded.jpg'));
    }

    public function test_the_client_chooses_where_to_keep_it_in_words(): void
    {
        $photo = Media::factory()->create(['filename' => 'cake.webp', 'original_name' => 'cake.jpg']);

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('edit')->table($photo), ['alt_text' => 'A birthday cake', 'focus' => 'top']);

        $photo->refresh();

        $this->assertSame(50, $photo->focal_x);
        $this->assertSame(15, $photo->focal_y);
        $this->assertSame('A birthday cake', $photo->alt_text);
    }

    public function test_the_ai_writes_a_first_draft_of_the_description(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        $photo = Media::factory()->create(['filename' => 'party.webp', 'path' => 'site-media/party.webp', 'thumbnail_path' => 'site-media/thumbnails/party.webp']);
        Storage::disk('public')->put('site-media/thumbnails/party.webp', 'not-really-a-webp');

        AltTextWriter::fake([['alt' => 'Children around a table with a birthday cake', 'decorative' => false]]);

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('describeWithAi')->table($photo))
            ->assertNotified('Described');

        $this->assertSame('Children around a table with a birthday cake', $photo->fresh()->alt_text);

        AltTextWriter::assertPromptedTimes(1);
    }

    public function test_a_decorative_picture_is_left_without_a_description_on_purpose(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        $photo = Media::factory()->create(['filename' => 'divider.webp', 'path' => 'site-media/divider.webp', 'thumbnail_path' => null, 'alt_text' => 'Old description']);
        Storage::disk('public')->put('site-media/divider.webp', 'not-really-a-webp');

        AltTextWriter::fake([['alt' => 'A row of coloured dots', 'decorative' => true]]);

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('describeWithAi')->table($photo))
            ->assertNotified('Left blank on purpose');

        $this->assertSame('', $photo->fresh()->alt_text);
    }

    public function test_the_ai_button_is_not_offered_when_there_is_no_ai(): void
    {
        $photo = Media::factory()->create();

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->assertActionHidden(TestAction::make('describeWithAi')->table($photo));
    }
}
