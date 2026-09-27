<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Filament\Resources\Submissions\Pages\ListSubmissions;
use Gadya\Cms\Jobs\PushFormSubmission;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Enquiries reach the Gadya Media portal as they arrive, so one nobody
 * answers is chased within the hour - and the visitor never waits on it.
 */
class PortalSubmissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
    }

    private function connect(): Connection
    {
        return Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
    }

    private function send(): void
    {
        $this->from('/contact')->post('/cms/forms/contact', [
            'name' => 'Pat Jones',
            'email' => 'pat@example.com',
            'phone' => '01273 000000',
            'message' => 'Can you do Saturday?',
        ])->assertRedirect('/contact')->assertSessionHas('gadya-cms.form.contact');
    }

    public function test_an_enquiry_is_sent_to_the_portal_the_moment_it_arrives(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response(['data' => ['id' => 55, 'status' => 'received']], 201)]);

        $this->send();

        $submission = FormSubmission::query()->firstOrFail();

        Http::assertSent(function (Request $request) use ($submission): bool {
            return $request->url() === 'https://portal.test/api/connect/v1/submissions'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Gadya-Signature')
                && $request['external_id'] === (string) $submission->id
                && $request['form'] === 'contact'
                && $request['name'] === 'Pat Jones'
                && $request['email'] === 'pat@example.com'
                && $request['phone'] === '01273 000000'
                && $request['message'] === 'Can you do Saturday?'
                && $request['fields']['name'] === 'Pat Jones'
                && str_ends_with((string) $request['page_url'], '/contact')
                && $request['callback_requested'] === false
                && $request['callback_consent_text'] === null
                && is_string($request['submitted_at']);
        });

        $this->assertNotNull($submission->pushed_at);
    }

    public function test_a_portal_that_is_down_never_reaches_the_visitor(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response('Bad gateway', 502)]);

        $this->send();

        $submission = FormSubmission::query()->firstOrFail();
        $this->assertNull($submission->pushed_at, 'Left for the sweep to send later.');
    }

    public function test_the_push_is_queued_rather_than_sent_while_the_visitor_waits(): void
    {
        $this->connect();
        Queue::fake();

        $this->send();

        Queue::assertPushed(PushFormSubmission::class, fn (PushFormSubmission $job): bool => $job->submission->is(FormSubmission::query()->first()));
    }

    public function test_nothing_is_sent_from_a_site_that_is_not_paired_or_has_it_switched_off(): void
    {
        Http::fake();

        $this->send();

        $this->connect();
        config(['gadya-cms.portal.push_submissions' => false]);

        $this->send();

        Http::assertNothingSent();
        $this->assertSame(2, FormSubmission::query()->count());
    }

    public function test_the_sweep_sends_what_the_queue_missed_from_the_last_week(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response(['data' => ['id' => 1, 'status' => 'received']], 201)]);

        $missed = $this->submission(['created_at' => now()->subDays(2)]);
        $sent = $this->submission(['created_at' => now()->subDay(), 'pushed_at' => now()->subDay()]);
        $ancient = $this->submission(['created_at' => now()->subDays(10)]);

        $this->artisan('gadya-cms:push-submissions')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['external_id'] === (string) $missed->id);
        $this->assertNotNull($missed->fresh()->pushed_at);
        $this->assertNull($ancient->fresh()->pushed_at);
    }

    public function test_a_refusal_that_will_never_change_is_not_retried_forever(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response(['message' => 'The form field is too long.'], 422)]);

        $submission = $this->submission();

        $this->artisan('gadya-cms:push-submissions')->assertSuccessful();

        $this->assertNotNull($submission->fresh()->pushed_at);
    }

    public function test_opening_and_answering_an_enquiry_in_the_panel_tells_the_portal(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response(['data' => []], 200)]);

        $submission = $this->submission(['pushed_at' => now()]);

        Livewire::actingAs($this->administrator())
            ->test(ListSubmissions::class)
            ->mountAction(TestAction::make('open')->table($submission));

        Http::assertSent(fn (Request $request): bool => $request->url() === "https://portal.test/api/connect/v1/submissions/{$submission->id}/status"
            && is_string($request['opened_at'])
            && $request['answered_at'] === null);

        $submission->fresh()->markAnswered();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/submissions/{$submission->id}/status")
            && is_string($request['answered_at']));
    }

    public function test_a_status_for_an_enquiry_the_portal_never_had_sends_the_enquiry_first(): void
    {
        $this->connect();
        Http::fake(['portal.test/*' => Http::response(['data' => []], 201)]);

        $submission = $this->submission();

        $submission->markRead();

        $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();

        $this->assertSame([
            'https://portal.test/api/connect/v1/submissions',
            "https://portal.test/api/connect/v1/submissions/{$submission->id}/status",
        ], $urls);
    }

    public function test_the_sweep_runs_every_five_minutes_on_the_sites_own_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'gadya-cms:push-submissions'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submission(array $attributes = []): FormSubmission
    {
        return FormSubmission::query()->create([
            'site_id' => 1,
            'form' => 'contact',
            'data' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello'],
            'path' => '/contact',
            'created_at' => now(),
            ...$attributes,
        ]);
    }
}
