<?php

namespace Gadya\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class MakeEditorCommand extends Command
{
    protected $signature = 'gadya-cms:editor {--name=} {--email=} {--password=} {--role=admin}';

    protected $description = 'Create a user who can sign in to the CMS and edit the site';

    public function handle(): int
    {
        $name = $this->option('name') ?? text('Name', required: true);
        $email = $this->option('email') ?? text('Email address', required: true);
        $plainPassword = $this->option('password') ?? password('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $model = config('auth.providers.users.model');

        $user = $model::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($plainPassword),
        ]);

        $user->forceFill(['role' => (string) $this->option('role')])->save();

        $this->info("Created CMS user {$email}.");

        return self::SUCCESS;
    }
}
