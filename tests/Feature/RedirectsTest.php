<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Resources\Redirects\Pages\ListRedirects;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class RedirectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function redirect(string $from, string $to, int $status = 301): Redirect
    {
        return Redirect::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => $status,
        ]);
    }

    public function test_an_old_address_is_sent_on_and_counted(): void
    {
        $redirect = $this->redirect('/Old-Page/', '/about');

        $this->assertSame('/old-page', $redirect->from_path);

        $this->get('/old-page')->assertRedirect('/about')->assertStatus(301);
        $this->get('/OLD-PAGE')->assertRedirect('/about');

        $this->assertSame(2, $redirect->fresh()->hits);
        $this->assertNotNull($redirect->fresh()->last_hit_at);
    }

    public function test_a_temporary_redirect_keeps_the_query_string(): void
    {
        $this->redirect('/promo', 'https://elsewhere.example/deal', 302);

        $this->get('/promo?ref=flyer')
            ->assertStatus(302)
            ->assertRedirect('https://elsewhere.example/deal?ref=flyer');
    }

    public function test_a_form_post_to_an_old_address_is_not_forwarded(): void
    {
        $this->redirect('/old-form', '/about');

        $this->post('/old-form')->assertNotFound();
    }

    public function test_a_change_takes_effect_without_clearing_any_cache(): void
    {
        $redirect = $this->redirect('/moved', '/about');
        $this->get('/moved')->assertRedirect('/about');

        $redirect->update(['to_path' => '/pricing']);
        $this->get('/moved')->assertRedirect('/pricing');

        $redirect->delete();
        $this->get('/moved')->assertNotFound();
    }

    public function test_the_panel_refuses_to_redirect_its_own_tools_away(): void
    {
        Livewire::actingAs($this->editor())
            ->test(ListRedirects::class)
            ->callAction('create', ['from_path' => '/admin/pages', 'to_path' => '/about', 'status_code' => 301])
            ->assertHasFormErrors(['from_path']);

        $this->assertDatabaseCount('gadyacms_redirects', 0);
    }

    public function test_an_editor_can_add_a_redirect_from_the_panel(): void
    {
        Livewire::actingAs($this->editor())
            ->test(ListRedirects::class)
            ->callAction('create', ['from_path' => '/summer-2025', 'to_path' => '/pricing', 'status_code' => 301])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('gadyacms_redirects', ['from_path' => '/summer-2025', 'to_path' => '/pricing']);
        $this->get('/summer-2025')->assertRedirect('/pricing');
    }
}
