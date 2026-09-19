<?php

namespace Gadya\Cms\Console;

use Filament\Facades\Filament;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\confirm;

/**
 * Everything between `composer require` and a working site, in one
 * command: the config file to edit, the tables, the first person who can
 * sign in, the document the application ships with loaded as the live
 * site, and the photos already in the repository indexed.
 *
 * Safe to run again: every step checks whether it has already happened.
 */
class InstallCommand extends Command
{
    protected $signature = 'gadya-cms:install
        {--force : Re-seed the document even if content already exists}
        {--no-admin : Do not offer to create the first administrator}
        {--admin-name= : Name for the first administrator}
        {--admin-email= : Email for the first administrator}
        {--admin-password= : Password for the first administrator}';

    protected $description = 'Set up the CMS for this application';

    public function handle(
        SiteContext $siteContext,
        SiteContentRepository $repository,
        PublishSiteContent $publish,
    ): int {
        $this->publishConfig();

        $this->components->task('Running migrations', fn (): bool => $this->callSilently('migrate', ['--force' => true]) === self::SUCCESS);

        $site = $siteContext->get();

        if ($site === null) {
            $this->components->error('No site could be created; check the database connection and run `php artisan migrate`.');

            return self::FAILURE;
        }

        $this->components->info("Site [{$site->key}] is ready.");

        if ($site->pages()->exists() && ! $this->option('force')) {
            $this->components->twoColumnDetail('Content', 'already present, skipped (pass --force to re-seed)');
        } else {
            $repository->saveDraft($repository->defaults());
            $publish->handle(null, 'Initial content');
            $this->components->twoColumnDetail('Content', 'seeded from the application defaults');
        }

        $this->callSilently(ImportLegacyMediaCommand::class);
        $this->components->twoColumnDetail('Photos', 'indexed');

        $this->callSilently('filament:assets');
        $this->components->twoColumnDetail('Panel assets', 'published');

        $this->publishUpdateWorkflow();

        $this->createAdministrator();

        if (Schema::hasTable('site_contents')) {
            $this->components->warn('A legacy [site_contents] table is present. Run `php artisan gadya-cms:import-legacy-content` to bring its content across.');
        }

        $this->newLine();
        $this->components->info('Done. Sign in at '.rescue(fn (): string => Filament::getPanel((string) config('gadya-cms.panel', 'admin'))->getUrl(), url('/admin'), report: false).' and press Publish when the site looks right.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $target = config_path('gadya-cms.php');

        if (File::exists($target)) {
            $this->components->twoColumnDetail('Config', 'config/gadya-cms.php already published');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'gadya-cms-config']);
        $this->components->twoColumnDetail('Config', 'published to config/gadya-cms.php');
    }

    /**
     * Whether anyone can already administer the site. A site that works
     * roles out for itself is asked in PHP rather than in SQL, because
     * there may be no column to ask about.
     */
    private function hasAdministrator(string $model, string $adminRole): bool
    {
        if ($this->usersHaveARoleColumn($model)) {
            return $model::query()->where('role', $adminRole)->exists();
        }

        return $model::query()->cursor()->contains(fn ($user): bool => $user->role === $adminRole);
    }

    private function usersHaveARoleColumn(string $model): bool
    {
        $user = new $model;

        return rescue(
            fn (): bool => Schema::connection($user->getConnectionName())->hasColumn($user->getTable(), 'role'),
            false,
            report: false,
        );
    }

    /**
     * The GitHub workflow that updates the Gadya packages and puts the
     * result in git, so the Gadya Media portal can run the update for this
     * site without anyone opening a terminal. Never overwritten: a site
     * may have tuned it.
     */
    private function publishUpdateWorkflow(): void
    {
        $target = base_path('.github/workflows/gadya-update.yml');

        if (File::exists($target)) {
            $this->components->twoColumnDetail('Update workflow', 'already present');

            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy(dirname(__DIR__, 2).'/resources/github/gadya-update.yml', $target);

        $this->components->twoColumnDetail('Update workflow', 'published to .github/workflows/gadya-update.yml');
    }

    /**
     * The first person who can sign in. Skipped when someone with the
     * administrator role already exists, or the command is told not to
     * ask - a deploy script has no one to answer the question.
     */
    private function createAdministrator(): void
    {
        if ($this->option('no-admin')) {
            return;
        }

        $model = (string) config('auth.providers.users.model');
        $adminRole = (string) config('gadya-cms.users.admin_role', 'admin');

        if ($this->hasAdministrator($model, $adminRole)) {
            $this->components->twoColumnDetail('Administrator', 'already exists');

            return;
        }

        /*
         * A site may work out a person's role for itself - from a flag, or
         * from another package's roles - rather than storing it in a
         * column. There is nobody to write a role to, so it makes its own
         * first account.
         */
        if (! $this->usersHaveARoleColumn($model)) {
            $this->components->twoColumnDetail('Administrator', 'this site decides roles for itself; make the first account the way it does');

            return;
        }

        $email = $this->option('admin-email');

        if ($email === null && (! $this->input->isInteractive() || ! confirm('Create the first administrator now?', default: true))) {
            $this->components->twoColumnDetail('Administrator', 'skipped; run `php artisan gadya-cms:editor` later');

            return;
        }

        $this->call(MakeEditorCommand::class, array_filter([
            '--name' => $this->option('admin-name'),
            '--email' => $email,
            '--password' => $this->option('admin-password'),
            '--role' => $adminRole,
        ], fn ($value): bool => $value !== null));
    }
}
