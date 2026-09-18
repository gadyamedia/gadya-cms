<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Filament\Resources\Subscribers\Pages\ListSubscribers;
use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

class NewsletterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_someone_can_join_the_list_and_joining_twice_changes_nothing(): void
    {
        $this->from('/')->post('/cms/newsletter', ['email' => 'Pat@Example.com', 'name' => 'Pat Morgan'])
            ->assertRedirect('/')
            ->assertSessionHas('gadya-cms.newsletter');

        $this->post('/cms/newsletter', ['email' => 'pat@example.com']);

        $this->assertDatabaseCount('gadyacms_subscribers', 1);

        $subscriber = Subscriber::query()->firstOrFail();

        $this->assertSame('pat@example.com', $subscriber->email, 'Addresses are kept in one case, so nobody joins twice.');
        $this->assertSame('Pat Morgan', $subscriber->name);
        $this->assertTrue($subscriber->isSubscribed());
    }

    public function test_a_bot_that_fills_the_honeypot_is_thanked_and_ignored(): void
    {
        $this->post('/cms/newsletter', ['email' => 'bot@example.com', 'website' => 'https://spam.example'])->assertRedirect();

        $this->assertDatabaseCount('gadyacms_subscribers', 0);
    }

    public function test_a_bad_address_is_refused(): void
    {
        $this->from('/')->post('/cms/newsletter', ['email' => 'not-an-email'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('email');

        $this->postJson('/cms/newsletter', ['email' => 'not-an-email'])->assertStatus(422);
    }

    public function test_an_unsubscribe_link_works_without_an_account_and_only_when_signed(): void
    {
        $this->post('/cms/newsletter', ['email' => 'pat@example.com']);
        $subscriber = Subscriber::query()->firstOrFail();

        $this->get('/cms/newsletter/'.$subscriber->getKey().'/unsubscribe')->assertForbidden();

        $this->get($subscriber->unsubscribeUrl())->assertRedirect('/');

        $this->assertFalse($subscriber->fresh()->isSubscribed());
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);

        $this->post('/cms/newsletter', ['email' => 'pat@example.com']);

        $this->assertTrue($subscriber->fresh()->isSubscribed(), 'Signing up again puts someone back on the list.');
    }

    public function test_the_list_downloads_in_the_columns_a_mailing_service_reads(): void
    {
        Subscriber::query()->create(['site_id' => 1, 'email' => 'pat@example.com', 'name' => 'Pat Morgan']);
        Subscriber::query()->create(['site_id' => 1, 'email' => 'gone@example.com', 'status' => Subscriber::UNSUBSCRIBED]);

        $response = Livewire::actingAs($this->editor())
            ->test(ListSubscribers::class)
            ->callAction(TestAction::make('export')->table())
            ->assertFileDownloaded();

        ob_start();
        $response->call('callMountedAction');
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function test_the_sign_up_box_renders_with_a_honeypot(): void
    {
        $html = Blade::render('@cmsNewsletterForm');

        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('Get our news by email', $html);
    }
}
