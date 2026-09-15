<?php

namespace Gadya\Cms;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Console\DoctorCommand;
use Gadya\Cms\Console\ExportSiteContentCommand;
use Gadya\Cms\Console\ImportLegacyContentCommand;
use Gadya\Cms\Console\ImportLegacyMediaCommand;
use Gadya\Cms\Console\InstallCommand;
use Gadya\Cms\Console\MakeEditorCommand;
use Gadya\Cms\Console\PruneAnalyticsCommand;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Content\SlugPagePaths;
use Gadya\Cms\Contracts\ResolvesPagePaths;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Events\PageViewed;
use Gadya\Cms\Http\Middleware\NoStoreWhenEditing;
use Gadya\Cms\Http\Middleware\TrackPageViews;
use Gadya\Cms\Livewire\MediaPicker;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Setting;
use Gadya\Cms\Observers\InvalidatePublishedDocument;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GadyaCmsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'gadya-cms';

    /**
     * The config file, views, translations and commands are wired by name;
     * the migrations and the editor's routes are loaded explicitly, because
     * they are real timestamped files and a route file rather than the
     * stubs the convention expects.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([
                InstallCommand::class,
                MakeEditorCommand::class,
                DoctorCommand::class,
                ExportSiteContentCommand::class,
                ImportLegacyContentCommand::class,
                ImportLegacyMediaCommand::class,
                PruneAnalyticsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->configureFilamentEcho();

        $this->app->bind(ResolvesPagePaths::class, fn (): ResolvesPagePaths => $this->app->make((string) config('gadya-cms.pages.paths', SlugPagePaths::class)));
        $this->app->singleton(SiteImage::class);
        $this->app->scoped(SiteContext::class);
        $this->app->scoped(EditContext::class);
        $this->app->scoped(SiteContentRepository::class);
        $this->app->scoped(Options::class);
        $this->app->scoped(AiSettings::class);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/editor.php');

        if (config('gadya-cms.blog.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/blog.php');
        }

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/gadya-cms'),
            __DIR__.'/../resources/css' => resource_path('css/vendor/gadya-cms'),
        ], 'gadya-cms-assets');

        $this->registerBladeDirectives();
        $this->registerRateLimiters();

        $this->recordLastLogin();
        $this->registerBroadcastChannel();

        Page::observe(InvalidatePublishedDocument::class);
        Setting::observe(InvalidatePublishedDocument::class);

        Livewire::component('gadya-cms.media-picker', MediaPicker::class);

        /*
         * The panel's brand skin. Registering it as a Filament asset means it
         * is published alongside Filament's own CSS by `filament:assets`,
         * which already runs on every deploy.
         */
        FilamentAsset::register([
            Css::make('gadya-cms-panel', __DIR__.'/../resources/css/panel.css'),
        ], package: 'gadya/cms');

        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', NoStoreWhenEditing::class);
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', TrackPageViews::class);

    }

    /**
     * The directives the site's own templates use to mark content as
     * editable and to resolve an image reference to a URL.
     */
    private function registerBladeDirectives(): void
    {
        Blade::directive('siteImage', fn (string $expression): string => "<?php echo e(app(\Gadya\Cms\Content\SiteImage::class)->url({$expression})); ?>");
        Blade::directive('siteThumbnail', fn (string $expression): string => "<?php echo e(app(\Gadya\Cms\Content\SiteImage::class)->thumbnailUrl({$expression})); ?>");
        Blade::directive('editable', fn (string $expression): string => "<?php echo app(\Gadya\Cms\Editor\EditContext::class)->attributes({$expression}); ?>");
        Blade::directive('editableGlobal', fn (string $expression): string => "<?php echo app(\Gadya\Cms\Editor\EditContext::class)->globalAttributes({$expression}); ?>");
        Blade::directive('editableFor', fn (string $expression): string => "<?php app(\Gadya\Cms\Editor\EditContext::class)->for({$expression}); ?>");
        Blade::directive('cmsToolbar', fn (): string => "<?php if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled()) { echo view('gadya-cms::editor.toolbar')->render(); } ?>");
    }

    /**
     * So the team list can answer the only question anyone asks of it: is
     * this person actually using the site, or did the invitation go stale?
     */
    private function recordLastLogin(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            rescue(
                fn () => $event->user->forceFill(['last_login_at' => now()])->saveQuietly(),
                report: false,
            );
        });
    }

    /**
     * Point Filament's bundled Echo client at whatever broadcaster the
     * application already configured.
     *
     * Filament builds `window.Echo` from `filament.broadcasting.echo`, so
     * without this every project would have to copy its Reverb or Pusher
     * connection details into a second config file to get a live panel.
     * An application that sets it itself is left alone, and when no
     * broadcaster is configured nothing is set at all - a socket that can
     * only fail is worse than the poll it would replace.
     */
    private function configureFilamentEcho(): void
    {
        if (! config('gadya-cms.analytics.live.enabled', true) || config('filament.broadcasting.echo') !== null) {
            return;
        }

        $connection = config('broadcasting.default');

        if (! in_array($connection, ['reverb', 'pusher'], true)) {
            return;
        }

        $options = config("broadcasting.connections.{$connection}.options", []);
        $key = config("broadcasting.connections.{$connection}.key");

        if (blank($key)) {
            return;
        }

        config(['filament.broadcasting.echo' => array_filter([
            'broadcaster' => $connection,
            'key' => $key,
            'cluster' => $options['cluster'] ?? null,
            'wsHost' => $options['host'] ?? null,
            'wsPort' => $options['port'] ?? null,
            'wssPort' => $options['port'] ?? null,
            'forceTLS' => ($options['scheme'] ?? 'https') === 'https',
            'authEndpoint' => '/broadcasting/auth',
            'enabledTransports' => ['ws', 'wss'],
            'disableStats' => true,
        ], fn ($value): bool => $value !== null)]);
    }

    /**
     * The live ticker is for people who may work on the site, and nobody
     * else. Registered here so an application adopting the package does not
     * have to remember to authorise a channel it did not create.
     */
    private function registerBroadcastChannel(): void
    {
        rescue(fn () => Broadcast::channel(
            PageViewed::channel(),
            fn ($user): bool => $user->can((string) config('gadya-cms.gate', 'manage-content')),
        ), report: false);
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('gadya-cms-inline', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('gadya-cms-events', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
    }
}
