<?php

namespace Gadya\Cms\Tests;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\GadyaCmsServiceProvider;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Tests\Fixtures\AdminPanelProvider;
use Gadya\Cms\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The package tested on its own, against a minimal host application: one
 * panel, one user model with a `role` column, and the two gates the CMS
 * guards its writes with. Anything a real application would add on top -
 * templates, routes, its own document - is deliberately absent.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('manage-content', fn (User $user): bool => $user->canManageContent());
        Gate::define('manage-users', fn (User $user): bool => $user->isAdministrator());
    }

    /**
     * Every provider the installed packages ask Laravel to discover, then
     * this package and the fixture panel. Read from Composer rather than
     * listed by hand so a Filament or Livewire release that adds a provider
     * does not silently leave the harness half-booted.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $installed = json_decode((string) file_get_contents(__DIR__.'/../vendor/composer/installed.json'), true);

        $discovered = collect($installed['packages'] ?? $installed)
            ->flatMap(fn (array $package): array => $package['extra']['laravel']['providers'] ?? [])
            ->filter(fn (string $provider): bool => class_exists($provider))
            ->values()
            ->all();

        return [...$discovered, GadyaCmsServiceProvider::class, AdminPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'http://cms.test');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('site', require __DIR__.'/Fixtures/site.php');
        $app['config']->set('livewire.inject_assets', false);
        $app['config']->set('livewire.csp_safe', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function editor(): User
    {
        return User::query()->create([
            'name' => 'Erin Editor',
            'email' => 'erin-'.fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'editor',
        ]);
    }

    protected function administrator(): User
    {
        return User::query()->create([
            'name' => 'Ada Admin',
            'email' => 'ada-'.fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    protected function visitor(): User
    {
        return User::query()->create([
            'name' => 'Vic Visitor',
            'email' => 'vic-'.fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'customer',
        ]);
    }

    /**
     * Put a document in place as the live site.
     *
     * @param  array<string, mixed>|null  $document
     */
    protected function publishDocument(?array $document = null): void
    {
        $repository = app(SiteContentRepository::class);

        $repository->saveDraft($document ?? $repository->defaults());
        app(PublishSiteContent::class)->handle();
        $repository->flushPublishedCache();

        Revision::query()->delete();
    }
}
