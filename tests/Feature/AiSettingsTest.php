<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Ai\Agents\ConnectionCheck;
use Gadya\Cms\Ai\AiNotConfigured;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Filament\Pages\AiSettings as AiSettingsPage;
use Gadya\Cms\Models\Option;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class AiSettingsTest extends TestCase
{
    public function test_nothing_is_configured_until_a_provider_model_and_key_are_saved(): void
    {
        $settings = app(AiSettings::class);

        $this->assertFalse($settings->isConfigured());

        $settings->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5']);
        $this->assertFalse($settings->isConfigured(), 'A key is still needed.');

        $settings->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        $this->assertTrue($settings->isConfigured());
    }

    public function test_a_self_hosted_service_needs_an_address_but_no_key(): void
    {
        $settings = app(AiSettings::class);

        $settings->save(['provider' => 'ollama', 'model' => 'llama3.1']);
        $this->assertFalse($settings->isConfigured());

        $settings->save(['provider' => 'ollama', 'model' => 'llama3.1', 'url' => 'http://localhost:11434']);
        $this->assertTrue($settings->isConfigured());
    }

    public function test_saving_without_a_key_keeps_the_one_already_stored(): void
    {
        $settings = app(AiSettings::class);

        $settings->save(['provider' => 'openai', 'model' => 'gpt-5', 'key' => 'sk-first']);
        $settings->save(['provider' => 'openai', 'model' => 'gpt-5-mini', 'key' => '']);

        $this->assertSame('sk-first', $settings->apiKey());
        $this->assertSame('gpt-5-mini', $settings->model());
    }

    public function test_registering_hands_the_chosen_service_to_the_ai_sdk(): void
    {
        $settings = app(AiSettings::class);
        $settings->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);

        $name = $settings->register();

        $this->assertSame('gadya-cms', $name);
        $this->assertSame('anthropic', config('ai.providers.gadya-cms.driver'));
        $this->assertSame('sk-ant-123', config('ai.providers.gadya-cms.key'));
    }

    public function test_registering_an_unconfigured_service_says_so_plainly(): void
    {
        $this->expectException(AiNotConfigured::class);

        app(AiSettings::class)->register();
    }

    public function test_an_editor_cannot_open_the_settings_screen(): void
    {
        $this->actingAs($this->editor())->get(AiSettingsPage::getUrl())->assertForbidden();
    }

    public function test_an_administrator_can_open_the_settings_screen(): void
    {
        $this->actingAs($this->administrator())->get(AiSettingsPage::getUrl())->assertOk()->assertSee('Test connection');
    }

    public function test_the_screen_saves_the_key_encrypted_and_never_shows_it_back(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5',
                'key' => 'sk-ant-from-the-form',
                'voice' => ['business' => 'Fun On Us', 'description' => 'Kids parties', 'tone' => 'Playful'],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['key' => null])
            ->assertDontSee('sk-ant-from-the-form');

        $this->assertSame('sk-ant-from-the-form', app(AiSettings::class)->apiKey());
        $this->assertSame('Playful', app(AiSettings::class)->voice()['tone']);
        $this->assertStringNotContainsString(
            'sk-ant-from-the-form',
            json_encode(Option::query()->pluck('value')),
        );
    }

    public function test_the_connection_test_reports_what_the_model_said(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);
        ConnectionCheck::fake(['OK']);

        Livewire::actingAs($this->administrator())
            ->test(AiSettingsPage::class)
            ->callAction('test')
            ->assertNotified('Connected');

        ConnectionCheck::assertPromptedTimes(1);
    }

    public function test_forgetting_the_key_leaves_the_service_unconfigured(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);

        Livewire::actingAs($this->administrator())
            ->test(AiSettingsPage::class)
            ->callAction('forgetKey')
            ->assertNotified('Key forgotten');

        $this->assertFalse(app(AiSettings::class)->isConfigured());
    }
}
