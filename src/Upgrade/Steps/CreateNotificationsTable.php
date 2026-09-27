<?php

namespace Gadya\Cms\Upgrade\Steps;

use Filament\Facades\Filament;
use Gadya\Cms\Upgrade\UpgradeSteps;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * The panel's bell - where a change drafted from a portal request is
 * announced - needs Laravel's notifications table. This adds the
 * migration `make:notifications-table` writes, once, to an application
 * whose panel runs Gadya CMS and that has no such migration yet.
 */
class CreateNotificationsTable
{
    public function key(): string
    {
        return 'cms.0.15.0.notifications-table';
    }

    public function description(): string
    {
        return 'Add the migration for Laravel\'s notifications table (the panel\'s bell)';
    }

    public function phase(): string
    {
        return UpgradeSteps::CODE;
    }

    public function shouldRun(): bool
    {
        return $this->panelUsesTheBell() && ! $this->migrationExists() && ! $this->schemaDumpHasTable();
    }

    public function run(): string
    {
        $exitCode = Artisan::call('make:notifications-table', ['--no-interaction' => true]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'make:notifications-table failed.');
        }

        return $output ?: 'Added the notifications table migration.';
    }

    private function panelUsesTheBell(): bool
    {
        return collect(rescue(fn (): array => Filament::getPanels(), [], report: false))
            ->contains(fn ($panel): bool => $panel->hasPlugin('gadya-cms'));
    }

    private function migrationExists(): bool
    {
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            if (str_contains(basename($path), 'create_notifications_table')
                || preg_match('/Schema::create\(\s*[\'"]notifications[\'"]/', (string) file_get_contents($path)) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A squashed schema (`schema:dump`) that already creates the table. */
    private function schemaDumpHasTable(): bool
    {
        foreach (glob(database_path('schema/*.sql')) ?: [] as $path) {
            if (preg_match('/create table\s+(if not exists\s+)?[`"\[]?notifications[`"\]]?\s*\(/i', (string) file_get_contents($path)) === 1) {
                return true;
            }
        }

        return false;
    }
}
