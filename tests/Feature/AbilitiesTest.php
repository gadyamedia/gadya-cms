<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Filament\Resources\Posts\PostResource;
use Gadya\Cms\Filament\Resources\Redirects\RedirectResource;
use Gadya\Cms\Tests\Fixtures\User;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

class AbilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function contributor(): User
    {
        return User::query()->create(['name' => 'Cal', 'email' => 'cal@example.com', 'password' => 'password', 'role' => 'contributor']);
    }

    public function test_each_role_gets_the_abilities_it_was_configured_with(): void
    {
        $abilities = app(Abilities::class);

        $this->assertSame(['articles', 'photos'], $abilities->forRole('contributor'));
        $this->assertNotContains('settings', $abilities->forRole('editor'));
        $this->assertContains('publish', $abilities->forRole('editor'));
        $this->assertSame(Abilities::all(), $abilities->forRole('admin'));
        $this->assertSame([], $abilities->forRole('customer'));
    }

    public function test_a_role_configured_as_a_plain_label_keeps_the_old_everything_but_the_team(): void
    {
        config(['gadya-cms.users.roles.helper' => 'Helper']);

        $this->assertSame(['content', 'articles', 'photos', 'enquiries', 'publish'], app(Abilities::class)->forRole('helper'));
        $this->assertSame('Helper', Abilities::roleLabels()['helper']);
    }

    public function test_a_contributor_reaches_articles_but_not_pages_or_settings(): void
    {
        $contributor = $this->contributor();

        $this->actingAs($contributor)->get(PostResource::getUrl())->assertOk();
        $this->actingAs($contributor)->get(PageResource::getUrl())->assertForbidden();
    }

    public function test_an_editor_is_kept_out_of_settings_and_a_contributor_cannot_publish(): void
    {
        $this->actingAs($this->editor())->get(RedirectResource::getUrl())->assertForbidden();

        $this->actingAs($this->contributor())
            ->withSession(['gadya-cms.editing' => true])
            ->post('/cms/publish')
            ->assertForbidden();

        $this->actingAs($this->editor())->post('/cms/publish')->assertRedirect();
    }

    public function test_the_application_may_override_an_ability_gate(): void
    {
        Gate::define(Abilities::gate(Abilities::PUBLISH), fn (): bool => false);

        $this->actingAs($this->administrator())->post('/cms/publish')->assertForbidden();
    }
}
