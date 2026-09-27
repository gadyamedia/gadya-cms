<?php

namespace Gadya\Cms\Portal\Commands;

use Gadya\Cms\Support\Backups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `backup.run`: take a backup now, before something risky - an upgrade,
 * a big import - rather than waiting for tonight's. Runs the site's own
 * `backup:run` from spatie/laravel-backup; a site without it says so
 * plainly instead of pretending.
 */
class RunBackup
{
    public function __construct(private readonly Backups $backups) {}

    public function type(): string
    {
        return 'backup.run';
    }

    /**
     * @param  array<string, mixed>  $payload  Optional `only_db` to skip the files.
     * @return array{output: string, result: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        if (! $this->backups->configured() || ! array_key_exists('backup:run', Artisan::all())) {
            throw new RuntimeException('This site takes no backups of its own: spatie/laravel-backup is not set up, so there is nothing to run.');
        }

        $exitCode = Artisan::call('backup:run', array_filter([
            '--only-db' => (bool) ($payload['only_db'] ?? false),
        ]));

        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException(Str::limit('The backup failed. '.$output, 5000, ''));
        }

        return [
            'output' => Str::limit($output !== '' ? $output : 'Backup taken.', 5000, ''),
            'result' => $this->backups->state(),
        ];
    }
}
