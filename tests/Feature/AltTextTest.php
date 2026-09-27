<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Ai\Agents\AltTextWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Filament\Pages\Quality;
use Gadya\Cms\Filament\Resources\Media\Pages\ListMedia;
use Gadya\Cms\Jobs\ProcessMediaUpload;
use Gadya\Cms\Jobs\WriteMissingAltText;
use Gadya\Cms\Models\Fix;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Quality\Drift;
use Gadya\Cms\Quality\PhotoDescriber;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Every photo says what it shows, or is marked as decoration whose right
 * description is none - and the hundred small chores of writing them can
 * be handed to AI in one go.
 */
class AltTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function withOwnKey(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
    }

    private function photo(array $attributes = []): Media
    {
        $photo = Media::factory()->create([
            'path' => 'site-media/'.fake()->unique()->slug(2).'.webp',
            'thumbnail_path' => null,
            'alt_text' => null,
            ...$attributes,
        ]);

        Storage::disk('public')->put($photo->path, 'not-really-a-webp');

        return $photo;
    }

    public function test_a_photo_cannot_be_saved_without_a_description_unless_it_is_decoration(): void
    {
        $photo = $this->photo();

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('edit')->table($photo), ['alt_text' => '', 'focus' => 'centre'])
            ->assertHasFormErrors(['alt_text' => 'required']);

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('edit')->table($photo), ['alt_text' => 'Left over', 'decorative' => true, 'focus' => 'centre'])
            ->assertHasNoFormErrors();

        $photo->refresh();
        $this->assertTrue($photo->decorative);
        $this->assertSame('', $photo->alt_text, 'Decoration is described as nothing, on purpose.');
    }

    public function test_an_upload_asks_what_it_shows_unless_it_is_decoration(): void
    {
        Queue::fake();

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('upload')->table(), ['uploads' => [UploadedFile::fake()->image('cake.jpg')]])
            ->assertHasFormErrors(['alt_text' => 'required']);

        $this->assertSame(0, Media::query()->count());

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('upload')->table(), ['uploads' => [UploadedFile::fake()->image('cake.jpg')], 'alt_text' => 'A birthday cake with five candles'])
            ->assertHasNoFormErrors();

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('upload')->table(), ['uploads' => [UploadedFile::fake()->image('dots.png')], 'decorative' => true])
            ->assertHasNoFormErrors();

        $this->assertSame('A birthday cake with five candles', Media::query()->where('original_name', 'cake.jpg')->sole()->alt_text);
        $this->assertTrue(Media::query()->where('original_name', 'dots.png')->sole()->decorative);
        Queue::assertPushed(ProcessMediaUpload::class, 2);
    }

    public function test_with_ai_an_upload_is_described_once_it_has_been_prepared(): void
    {
        $this->withOwnKey();
        Queue::fake();

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->callAction(TestAction::make('upload')->table(), ['uploads' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])
            ->assertHasNoFormErrors();

        Queue::assertPushed(WriteMissingAltText::class, fn (WriteMissingAltText $job): bool => $job->mediaIds === Media::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_the_bulk_action_describes_only_what_is_missing_a_handful_at_a_time(): void
    {
        $this->withOwnKey();
        Queue::fake();

        $missing = collect(range(1, 12))->map(fn (): Media => $this->photo());
        $decoration = $this->photo(['decorative' => true, 'alt_text' => '']);
        $described = $this->photo(['alt_text' => 'Already said']);

        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->selectTableRecords([...$missing, $decoration, $described])
            ->callAction(TestAction::make('writeMissingAltText')->table()->bulk())
            ->assertNotified('Describing 12 photos');

        Queue::assertPushed(WriteMissingAltText::class, 2);

        $queued = Queue::pushed(WriteMissingAltText::class)->flatMap(fn (WriteMissingAltText $job): array => $job->mediaIds)->sort()->values()->all();
        $this->assertSame($missing->pluck('id')->sort()->values()->all(), $queued);
    }

    public function test_the_bulk_action_is_not_offered_without_ai(): void
    {
        Livewire::actingAs($this->editor())
            ->test(ListMedia::class)
            ->assertActionHidden(TestAction::make('writeMissingAltText')->table()->bulk());
    }

    public function test_the_writer_describes_photos_and_recognises_decoration(): void
    {
        $this->withOwnKey();
        $party = $this->photo();
        $divider = $this->photo();
        $skipped = $this->photo(['decorative' => true, 'alt_text' => '']);

        AltTextWriter::fake([
            ['alt' => 'Children around a table with a birthday cake', 'decorative' => false],
            ['alt' => 'A row of dots', 'decorative' => true],
        ]);

        (new WriteMissingAltText([$party->id, $divider->id, $skipped->id]))->handle(app(PhotoDescriber::class));

        $this->assertSame('Children around a table with a birthday cake', $party->fresh()->alt_text);
        $this->assertTrue($divider->fresh()->decorative);
        $this->assertSame('', $divider->fresh()->alt_text);
        AltTextWriter::assertPromptedTimes(2);
        $this->assertSame('site', Fix::query()->sole()->written_by);
    }

    public function test_without_a_key_of_its_own_the_site_asks_gadya(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
        Http::fake(['portal.test/*' => Http::response(['text' => 'A dentist smiling at a patient.'])]);
        $photo = $this->photo();

        (new WriteMissingAltText([$photo->id]))->handle(app(PhotoDescriber::class));

        $this->assertSame('A dentist smiling at a patient', $photo->fresh()->alt_text);
        $this->assertSame('gadya', Fix::query()->sole()->written_by);
    }

    public function test_decoration_is_not_counted_as_a_photo_nobody_described(): void
    {
        $this->photo();
        $this->photo(['decorative' => true, 'alt_text' => '']);

        $this->assertSame(1, Media::query()->missingAltText()->count());
        $this->assertStringStartsWith('1 photo has no description', app(Drift::class)->findings()->firstWhere('key', 'undescribed-photos')['says']);

        Livewire::actingAs($this->administrator())
            ->test(Quality::class)
            ->assertSee('1 photo')
            ->assertSee('Needs a description');
    }
}
