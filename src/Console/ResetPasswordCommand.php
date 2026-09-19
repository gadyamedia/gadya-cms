<?php

namespace Gadya\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Sets a new password for someone who cannot get into the panel: the
 * client who has forgotten hers, or an account whose reset email never
 * arrives because the site cannot send mail yet.
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'gadya-cms:password {--email=} {--password=} {--generate : Make a strong password instead of choosing one}';

    protected $description = 'Set a new password for someone who signs in to the CMS';

    public function handle(): int
    {
        $email = $this->option('email') ?? text('Email address', required: true);
        $generated = (bool) $this->option('generate');
        $plainPassword = match (true) {
            $generated => Str::password(16),
            $this->option('password') !== null => (string) $this->option('password'),
            default => password('New password', required: true),
        };

        $validator = Validator::make(
            ['email' => $email, 'password' => $plainPassword],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $model = config('auth.providers.users.model');
        $user = $model::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Nobody signs in with {$email}. `php artisan gadya-cms:editor` creates an account.");

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($plainPassword)])->save();

        /*
         * Password changes end the sessions opened with the old one, so
         * anyone signed in elsewhere with it is signed out.
         */
        if (in_array('remember_token', $user->getFillable(), true) || $user->getAttribute('remember_token') !== null) {
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }

        $this->components->info("New password set for {$email}.");

        if ($generated) {
            $this->components->warn("The password is: {$plainPassword}");
            $this->components->info('Give it to them by a means other than email, and ask them to change it under Your account.');
        }

        return self::SUCCESS;
    }
}
