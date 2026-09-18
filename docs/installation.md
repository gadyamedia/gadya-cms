# Installation

## Requirements

- PHP 8.3 or 8.4
- Laravel 13
- Filament 5
- A user model with a `role` column (any string values; the package only needs to know which one is the administrator)

## Install

```bash
composer require gadya/cms
php artisan gadya-cms:install
```

The install command does everything between `composer require` and a working site, and can be run again without harm:

1. Publishes `config/gadya-cms.php`.
2. Runs the migrations.
3. Seeds the site document from your `config/site.php` (see [The site document](site-document.md)) as both the draft and the live site.
4. Indexes any images already in `public/images/site` into the photo library.
5. Publishes the panel's stylesheet with `filament:assets`.
6. Offers to create the first administrator (skipped with `--no-admin`, or given `--admin-name`, `--admin-email` and `--admin-password` for a script).

## Register the plugin

```php
use Gadya\Cms\Filament\GadyaCmsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('admin')
        ->path('admin')
        ->login()
        ->passwordReset()   // invitations ride the reset flow
        ->plugins([
            GadyaCmsPlugin::make(),
        ]);
}
```

Do not register a Dashboard page of your own: the package's dashboard sits at the panel root.

## Gates and the user model

The package guards every screen and every write with two gates. Define them in a service provider:

```php
Gate::define('manage-content', fn (User $user): bool => in_array($user->role, ['editor', 'admin'], true));
Gate::define('manage-users', fn (User $user): bool => $user->role === 'admin');
```

Implement `Filament\Models\Contracts\FilamentUser` on the model and return the same answer from `canAccessPanel()`. Tell the package what your roles are called in `config/gadya-cms.php`:

```php
'users' => [
    'roles' => ['editor' => 'Editor', 'admin' => 'Administrator'],
    'default_role' => 'editor',
    'admin_role' => 'admin',
],
```

Keep `role` out of `$fillable`: the package writes it with `forceFill`, so a signup form can never grant anyone a role.

## Livewire

If your public site keeps Livewire's asset injection off (`config/livewire.php`, `inject_assets => false`) the panel still works: it injects the runtime through its own render hooks. `csp_safe` must be `false` - Filament's own JavaScript needs the standard build.

## Taking only the parts you want

Every feature is a switch on the plugin:

```php
GadyaCmsPlugin::make()
    ->analytics(false)    // no dashboard figures
    ->team(false)         // no invitations
    ->brand(false)        // stock Filament chrome
    ->blog(false)         // no articles
    ->ai(false)           // nothing written by AI
    ->redirects(false)    // no redirects table
    ->forms(false)        // no enquiries inbox
    ->search(false)       // no Search Console, PageSpeed or readiness cards
    ->events(false)       // no diary or calendar feed
    ->newsletter(false)   // no mailing list
    ->profile(false)      // no profile page (you have your own)
    ->unsavedChangesAlerts(false)
    ->navigationGroups(content: 'Website', appearance: 'Design');
```

Read the configuration back anywhere with `GadyaCmsPlugin::get()`.

## Scheduling

In `routes/console.php`:

```php
Schedule::command('gadya-cms:prune-analytics')->weeklyOn(1, '03:00');
Schedule::command('gadya-cms:analytics-digest')->weeklyOn(1, '08:00');
Schedule::command('gadya-cms:search-console')->dailyAt('05:00');
Schedule::command('gadya-cms:publish-due')->everyFiveMinutes();
Schedule::command('gadya-cms:prune-trash')->daily();
```

The full list is in [Running a site](operations.md).

## Queues

Photo processing, article writing and form emails are queued. Run a worker in production (`php artisan queue:work`) or set `QUEUE_CONNECTION=sync` on a small site and accept that uploads take a moment.

## Upgrading

```bash
composer update gadya/cms
php artisan migrate
php artisan filament:assets
php artisan optimize:clear
```

Then read [upgrading.md](upgrading.md) for anything a release asks of you.
