<?php

namespace Gadya\Cms\Services;

use Filament\Facades\Filament;
use Gadya\Cms\Notifications\PanelInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Brings someone onto the team.
 *
 * The account is created with an unguessable password nobody is ever told,
 * and the invitation carries a reset token instead - so the only way in is
 * through the link, and the link expires.
 */
class InvitePanelUser
{
    public function invite(string $name, string $email, string $role, ?Authenticatable $invitedBy = null): Model
    {
        $model = config('auth.providers.users.model');

        /** @var Model $user */
        $user = $model::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'invited_at' => now(),
        ]);

        if (! $user->exists) {
            $user->forceFill(['password' => Hash::make(Str::random(64))]);
        }

        $user->save();

        $this->sendInvitation($user, $invitedBy);

        return $user;
    }

    /**
     * Someone added by hand, with a password the administrator chose, for
     * the times an email is the wrong way in: a colleague in the room, an
     * inbox that eats automatic mail. Nothing is sent.
     */
    public function createWithPassword(string $name, string $email, string $role, string $password): Model
    {
        $model = config('auth.providers.users.model');

        /** @var Model $user */
        $user = $model::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => Hash::make($password),
            'invited_at' => now(),
        ])->save();

        return $user;
    }

    public function setPassword(Model $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)])->save();
    }

    /**
     * What to send them, in words: where to sign in, with what, and to
     * change the password once they are in.
     */
    public static function signInInstructions(string $name, string $email, string $password): string
    {
        $site = (string) config('gadya-cms.brand.name', config('app.name'));
        $url = rescue(
            fn (): string => Filament::getPanel((string) config('gadya-cms.panel', 'admin'))->getLoginUrl(),
            url('/admin/login'),
            report: false,
        );

        $first = trim(explode(' ', trim($name))[0] ?? '') ?: 'there';

        return implode("\n", [
            "Hi {$first},",
            '',
            "You can now sign in to help manage the {$site} website.",
            '',
            "Sign in at: {$url}",
            "Email: {$email}",
            "Password: {$password}",
            '',
            'Once you are in, please change your password: click your initials in the top right corner, choose Profile, and set a new one.',
        ]);
    }

    /**
     * Send - or re-send - the invitation. Issuing a fresh token invalidates
     * the previous link, so a forwarded invitation stops working the moment
     * someone asks for another.
     */
    public function sendInvitation(Model $user, ?Authenticatable $invitedBy = null): void
    {
        $user->forceFill(['invited_at' => now()])->save();

        $user->notify(new PanelInvitation(
            Password::createToken($user),
            $invitedBy?->name,
        ));
    }
}
