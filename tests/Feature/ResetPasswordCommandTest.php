<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class ResetPasswordCommandTest extends TestCase
{
    public function test_it_sets_the_password_it_is_given_and_signs_the_old_sessions_out(): void
    {
        $user = $this->administrator();
        $user->forceFill(['password' => Hash::make('the-old-password'), 'remember_token' => 'stale-token'])->save();

        $this->artisan('gadya-cms:password', ['--email' => $user->email, '--password' => 'a-brand-new-password'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
        $this->assertNotSame('stale-token', $user->remember_token, 'Anyone signed in with the old password is signed out.');
    }

    public function test_it_generates_a_password_when_none_is_given_and_shows_it_once(): void
    {
        $user = $this->editor();
        $user->forceFill(['password' => Hash::make('the-old-password')])->save();

        $this->artisan('gadya-cms:password', ['--email' => $user->email, '--generate' => true])
            ->expectsOutputToContain('The password is:')
            ->assertSuccessful();

        $this->assertFalse(Hash::check('the-old-password', $user->refresh()->password));
    }

    public function test_it_says_so_when_nobody_signs_in_with_that_address(): void
    {
        $this->artisan('gadya-cms:password', ['--email' => 'nobody@example.com', '--password' => 'a-brand-new-password'])
            ->expectsOutputToContain('gadya-cms:editor')
            ->assertFailed();
    }
}
