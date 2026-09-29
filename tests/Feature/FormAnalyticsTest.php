<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Analytics\FormAnalytics;
use Gadya\Cms\Filament\Pages\Dashboard;
use Gadya\Cms\Filament\Resources\Forms\Pages\FormStats;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormEvent;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * A form's own figures: seen, started, sent, where people stop on a long
 * form, how long it takes - counted as privately as the dashboard is.
 */
class FormAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
    }

    private function form(): Form
    {
        return Form::factory()->published()->withFields([
            ['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true],
            ['type' => 'page_break', 'label' => 'Details'],
            ['type' => 'long_text', 'key' => 'details', 'label' => 'Details'],
            ['type' => 'page_break', 'label' => 'Last bit'],
            ['type' => 'short_text', 'key' => 'how', 'label' => 'How did you hear of us?'],
        ])->create(['slug' => 'quote', 'title' => 'Quote']);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function beacon(string $name, ?int $step = null, array $server = []): void
    {
        $this->withServerVariables($server)
            ->postJson('/cms/forms/quote/events', ['name' => $name, 'step' => $step, 'path' => '/pricing', 'referrer' => 'https://www.google.com/search'])
            ->assertOk();
    }

    public function test_views_starts_steps_and_sends_are_counted_per_person(): void
    {
        $form = $this->form();
        $alice = ['REMOTE_ADDR' => '10.0.0.1'];
        $bob = ['REMOTE_ADDR' => '10.0.0.2'];
        $cara = ['REMOTE_ADDR' => '10.0.0.3'];

        foreach ([$alice, $bob, $cara, $alice] as $person) {
            $this->beacon('view', server: $person);
        }

        $this->beacon('start', server: $alice);
        $this->beacon('start', server: $bob);
        $this->beacon('step', 1, $alice);
        $this->beacon('step', 1, $bob);
        $this->beacon('step', 2, $alice);

        $this->travel(-95)->seconds();
        $seal = app(SpamGuard::class)->seal('quote');
        $this->travelBack();

        $this->withServerVariables($alice)->postJson('/cms/forms/quote', ['_t' => $seal, 'email' => 'a@example.com', 'details' => 'A kitchen', '_path' => '/pricing'])->assertOk();

        $stats = app(FormAnalytics::class)->for($form);

        $this->assertSame(3, $stats['views']);
        $this->assertSame(2, $stats['starts']);
        $this->assertSame(1, $stats['completions']);
        $this->assertSame(33.3, $stats['conversion']);
        $this->assertSame(95, $stats['average_seconds']);
        $this->assertSame([
            ['step' => 1, 'title' => 'Step 1', 'people' => 2, 'dropped' => 0],
            ['step' => 2, 'title' => 'Details', 'people' => 2, 'dropped' => 1],
            ['step' => 3, 'title' => 'Last bit', 'people' => 1, 'dropped' => 0],
        ], $stats['steps']);
        $this->assertSame(['www.google.com' => 4], $stats['sources']);
        $this->assertSame(5, $stats['pages']['/pricing']);
    }

    public function test_someone_who_said_no_to_analytics_is_not_counted_seeing_the_form(): void
    {
        $this->form();

        $this->withCredentials()->withUnencryptedCookie('gadya_consent', rawurlencode(json_encode(['v' => 1, 'necessary' => true, 'analytics' => false, 'marketing' => false])))
            ->postJson('/cms/forms/quote/events', ['name' => 'view', 'path' => '/'])
            ->assertOk();

        $this->postJson('/cms/forms/quote/events', ['name' => 'view', 'path' => '/'], ['User-Agent' => 'Googlebot/2.1'])->assertOk();
        $this->postJson('/cms/forms/quote/events', ['name' => 'invented', 'path' => '/'])->assertStatus(422);
        $this->postJson('/cms/forms/nothing/events', ['name' => 'view', 'path' => '/'])->assertOk();

        $this->assertDatabaseCount('gadyacms_form_events', 0);
    }

    public function test_the_forms_figures_and_the_dashboard_show_them(): void
    {
        $form = $this->form();
        $this->beacon('view');
        FormEvent::query()->create(['site_id' => $form->site_id, 'form_id' => $form->getKey(), 'name' => FormEvent::COMPLETE, 'created_at' => now()]);

        Livewire::actingAs($this->editor())
            ->test(FormStats::class, ['record' => $form->getKey()])
            ->assertSee('Where people stop')
            ->assertSee('100%')
            ->call('setRange', 7)
            ->assertSet('days', 7);

        Livewire::actingAs($this->editor())
            ->test(Dashboard::class)
            ->assertSee('Forms')
            ->assertSee('Quote');
    }

    public function test_old_form_figures_are_pruned_with_the_rest(): void
    {
        $form = $this->form();
        FormEvent::query()->create(['site_id' => $form->site_id, 'form_id' => $form->getKey(), 'name' => FormEvent::VIEW, 'created_at' => now()->subDays(400)]);

        $this->artisan('gadya-cms:prune-analytics')->assertSuccessful();

        $this->assertDatabaseCount('gadyacms_form_events', 0);
    }
}
