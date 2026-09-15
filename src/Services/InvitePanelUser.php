<?php

namespace Gadya\Cms\Services;

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
