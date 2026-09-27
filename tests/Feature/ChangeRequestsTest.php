<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Ai\Agents\ChangeWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Resources\ChangeRequests\Pages\ListChangeRequests;
use Gadya\Cms\Models\ChangeRequest;
use Gadya\Cms\Portal\Commands\RequestContentChange;
use Gadya\Cms\Portal\RemoteCommands;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\PortalSummary;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;

/**
 * "Please change X on the website", asked in the portal: drafted here by
 * AI, never published by it, and settled by a person.
 */
class ChangeRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function withOwnKey(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'request_id' => 42,
            'instructions' => 'Please change the heading on the about page to "Meet the team".',
            'page_url' => 'https://example.test/about',
            'requested_by' => 'Ada Owner',
            ...$overrides,
        ];
    }

    private function draft(): array
    {
        return app(SiteContentRepository::class)->draft();
    }

    private function live(): array
    {
        app(SiteContentRepository::class)->flushPublishedCache();

        return app(SiteContentRepository::class)->published();
    }

    public function test_the_change_is_drafted_for_a_person_to_check_and_never_published(): void
    {
        $this->withOwnKey();
        $editor = $this->editor();
        ChangeWriter::fake([['page' => 'about', 'changes' => [['field' => 'heading', 'value' => 'Meet the team']], 'reason' => 'Changed the heading.']]);

        $outcome = app(RequestContentChange::class)->handle($this->payload());

        $this->assertSame('Meet the team', $this->draft()['pages']['about']['heading']);
        $this->assertSame('Who we are', $this->live()['pages']['about']['heading'], 'Nothing is live until someone publishes.');

        $result = $outcome['result'];
        $this->assertSame('drafted', $result['status']);
        $this->assertSame('About us', $result['page_title']);
        $this->assertStringEndsWith('/about', $result['page_url']);
        $this->assertStringContainsString('/cms/preview', $result['preview_url']);
        $this->assertStringContainsString('/admin/pages/', (string) $result['edit_url']);
        $this->assertSame([['field' => 'heading', 'before' => 'Who we are', 'after' => 'Meet the team']], $result['changes']);
        $this->assertStringContainsString('Drafted 1 change on About us', $outcome['output']);

        $request = ChangeRequest::query()->sole();
        $this->assertSame(42, $request->portal_id);
        $this->assertSame('Ada Owner', $request->requested_by);

        ChangeWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Meet the team')
            && str_contains($prompt->prompt, 'heading: Who we are')
            && ! str_contains($prompt->prompt, 'What it costs'));

        $this->assertSame(1, $editor->notifications()->count(), 'Whoever edits the site hears about it in the panel.');
        $this->assertStringContainsString('ready for you to check', (string) $editor->notifications()->first()->data['title']);
    }

    public function test_the_ai_chooses_the_page_when_the_client_did_not_say(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake([['page' => 'pricing', 'changes' => [['field' => 'heading', 'value' => 'Prices for 2027']], 'reason' => '']]);

        $result = app(RequestContentChange::class)->handle($this->payload(['page_url' => null, 'instructions' => 'Update the prices heading for next year.']))['result'];

        $this->assertSame('Pricing', $result['page_title']);
        $this->assertSame('Prices for 2027', $this->draft()['pages']['pricing']['heading']);

        ChangeWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Page "about"')
            && ! str_contains($prompt->prompt, 'Page "old-offer"'));
    }

    public function test_a_request_nothing_on_the_page_can_answer_fails_with_the_reason(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake([['page' => 'about', 'changes' => [['field' => 'hero_image', 'value' => 'new.webp'], ['field' => 'type', 'value' => 'legal']], 'reason' => 'That needs a new photo, which a person has to choose.']]);

        try {
            app(RequestContentChange::class)->handle($this->payload());
            $this->fail('A request that changes nothing must fail the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame('That needs a new photo, which a person has to choose.', $exception->getMessage());
        }

        $this->assertSame(0, ChangeRequest::query()->count());
        $this->assertSame('content', $this->draft()['pages']['about']['type']);
    }

    public function test_an_address_with_no_page_behind_it_fails_before_asking_the_ai(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no page at /nowhere');

        try {
            app(RequestContentChange::class)->handle($this->payload(['page_url' => '/nowhere']));
        } finally {
            ChangeWriter::assertNeverPrompted();
        }
    }

    public function test_the_same_request_delivered_twice_is_drafted_once(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake([['page' => 'about', 'changes' => [['field' => 'heading', 'value' => 'Meet the team']], 'reason' => '']]);

        app(RequestContentChange::class)->handle($this->payload());
        $again = app(RequestContentChange::class)->handle($this->payload());

        $this->assertSame('drafted', $again['result']['status']);
        $this->assertSame(1, ChangeRequest::query()->count());
        ChangeWriter::assertPromptedTimes(1);
    }

    public function test_publishing_settles_it_and_the_check_in_tells_the_portal(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake([['page' => 'about', 'changes' => [['field' => 'heading', 'value' => 'Meet the team']], 'reason' => '']]);
        app(RequestContentChange::class)->handle($this->payload());

        $this->assertSame([['id' => 42, 'status' => 'drafted']], array_map(
            fn (array $row): array => ['id' => $row['id'], 'status' => $row['status']],
            app(PortalSummary::class)->build()['change_requests'],
        ));

        app(PublishSiteContent::class)->handle();

        $this->assertSame('published', ChangeRequest::query()->sole()->status);
        $this->assertSame('Meet the team', $this->live()['pages']['about']['heading']);
        $this->assertSame('published', app(PortalSummary::class)->build()['change_requests'][0]['status']);
    }

    public function test_a_change_undone_by_hand_before_a_publish_is_reported_as_discarded(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake([['page' => 'about', 'changes' => [['field' => 'heading', 'value' => 'Meet the team']], 'reason' => '']]);
        app(RequestContentChange::class)->handle($this->payload());

        $document = $this->draft();
        $document['pages']['about']['heading'] = 'Who we are';
        app(SiteContentRepository::class)->saveDraft($document);

        app(PublishSiteContent::class)->handle();

        $this->assertSame('discarded', ChangeRequest::query()->sole()->status);
    }

    public function test_an_editor_can_discard_it_and_the_draft_goes_back(): void
    {
        $this->withOwnKey();
        ChangeWriter::fake([['page' => 'about', 'changes' => [
            ['field' => 'heading', 'value' => 'Meet the team'],
            ['field' => 'description', 'value' => 'Four of us, since 2004.'],
        ], 'reason' => '']]);
        app(RequestContentChange::class)->handle($this->payload());

        /* Someone rewrote one of the two fields by hand since; theirs stays. */
        $document = $this->draft();
        $document['pages']['about']['description'] = 'Written by a person.';
        app(SiteContentRepository::class)->saveDraft($document);

        Livewire::actingAs($this->editor())
            ->test(ListChangeRequests::class)
            ->callAction(TestAction::make('discard')->table(ChangeRequest::query()->sole()))
            ->assertNotified('Discarded');

        $this->assertSame('discarded', ChangeRequest::query()->sole()->status);
        $this->assertSame('Who we are', $this->draft()['pages']['about']['heading']);
        $this->assertSame('Written by a person.', $this->draft()['pages']['about']['description']);
    }

    public function test_a_site_without_its_own_key_asks_gadya_through_the_portal(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
        Http::fake(['portal.test/*' => Http::response(['text' => "```json\n{\"page\": \"about\", \"changes\": [{\"field\": \"heading\", \"value\": \"Meet the team\"}], \"reason\": \"\"}\n```"])]);

        app(RequestContentChange::class)->handle($this->payload());

        $this->assertSame('Meet the team', $this->draft()['pages']['about']['heading']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.test/api/connect/v1/ai'
            && str_contains((string) $request['prompt'], 'Meet the team'));
    }

    public function test_connect_finds_the_handler_by_its_tag(): void
    {
        $this->assertContains('content.request', collect(app()->tagged(RemoteCommands::TAG))->map->type()->all());
    }
}
