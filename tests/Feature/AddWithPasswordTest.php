<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Filament\Resources\Users\Pages\ListUsers;
use Gadya\Cms\Services\InvitePanelUser;
use Gadya\Cms\Tests\Fixtures\User;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class AddWithPasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
    }

    public function test_someone_can_be_added_with_a_password_and_nothing_is_emailed(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(ListUsers::class)
            ->callAction(TestAction::make('createWithPassword')->table(), [
                'name' => 'Pat Morgan',
                'email' => 'pat@example.com',
                'role' => 'editor',
                'password' => 'a-good-long-password',
            ])
            ->assertActionMounted('credentials');

        $pat = User::query()->where('email', 'pat@example.com')->firstOrFail();

        $this->assertSame('editor', $pat->role);
        $this->assertTrue(Hash::check('a-good-long-password', $pat->password), 'They can sign in with exactly what was typed.');
        Notification::assertNothingSent();
    }

    public function test_the_details_handed_over_say_where_and_with_what(): void
    {
        $text = InvitePanelUser::signInInstructions('Pat Morgan', 'pat@example.com', 'a-good-long-password');

        $this->assertStringStartsWith('Hi Pat,', $text);
        $this->assertStringContainsString('Sign in at: '.url('/admin/login'), $text);
        $this->assertStringContainsString('Email: pat@example.com', $text);
        $this->assertStringContainsString('Password: a-good-long-password', $text);
        $this->assertStringContainsString('change your password', $text);
    }

    public function test_a_short_password_or_a_taken_address_is_refused(): void
    {
        $taken = $this->editor();

        Livewire::actingAs($this->administrator())
            ->test(ListUsers::class)
            ->callAction(TestAction::make('createWithPassword')->table(), ['name' => 'X', 'email' => $taken->email, 'role' => 'editor', 'password' => 'short'])
            ->assertHasActionErrors(['email', 'password']);
    }

    public function test_an_administrator_can_set_a_new_password_for_someone(): void
    {
        $editor = $this->editor();

        Livewire::actingAs($this->administrator())
            ->test(ListUsers::class)
            ->callAction(TestAction::make('setPassword')->table($editor), ['password' => 'another-long-password'])
            ->assertActionMounted('credentials');

        $this->assertTrue(Hash::check('another-long-password', $editor->fresh()->password));
        Notification::assertNothingSent();
    }
}
