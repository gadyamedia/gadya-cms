<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

/**
 * The demo host: a small party-hire site that uses every feature of the
 * package, so it can be tried, screenshotted and developed against
 * without a client project in the way.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'auth.providers.users.model' => User::class,
            'site' => require __DIR__.'/../../config/site.php',
            'gadya-cms.brand.name' => 'Springfield Parties',
            'gadya-cms.brand.primary' => '#0f766e',
            'gadya-cms.brand.secondary' => '#f97316',
            'gadya-cms.brand.background' => '#f8fafc',
            'gadya-cms.brand.accent' => '#fde68a',
            'gadya-cms.brand.fonts' => ['display' => 'Georgia', 'body' => 'Inter', 'stylesheet' => null],
            'gadya-cms.blog.layout' => 'layouts.app',
            'gadya-cms.blog.heading' => 'Party ideas and news',
            'gadya-cms.seo.site_name' => 'Springfield Parties',
            'gadya-cms.seo.title_suffix' => ' | Springfield Parties',
            'gadya-cms.forms.forms.contact.notify' => ['hello@example.test'],
            'gadya-cms.blog.comments.enabled' => true,
            'gadya-cms.blog.comments.notify' => ['hello@example.test'],
            'gadya-cms.navigation.menus' => ['primary' => 'Main menu', 'footer' => 'Footer menu'],
            'livewire.inject_assets' => false,
            'livewire.csp_safe' => false,
        ]);
    }

    public function boot(): void
    {
        /*
         * The editor assets, served raw: a demo has no Vite build, and the
         * editor is plain ES modules and plain CSS.
         */
        $target = public_path('vendor/gadya-cms');

        if (! is_dir($target)) {
            File::copyDirectory(__DIR__.'/../../../resources/js', $target);
            File::copyDirectory(__DIR__.'/../../../resources/css', $target);
        }

        if (! file_exists(public_path('storage')) && is_dir(storage_path('app/public'))) {
            File::link((string) realpath(storage_path('app/public')), (string) realpath(public_path()).'/storage');
        }

        Gate::define('manage-content', fn (User $user): bool => $user->canManageContent());
        Gate::define('manage-users', fn (User $user): bool => $user->role === 'admin');
    }
}
