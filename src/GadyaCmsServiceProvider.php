<?php

namespace Gadya\Cms;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Console\AgentReadyCommand;
use Gadya\Cms\Console\AuditCommand;
use Gadya\Cms\Console\CheckLinksCommand;
use Gadya\Cms\Console\CheckPageSpeedCommand;
use Gadya\Cms\Console\DoctorCommand;
use Gadya\Cms\Console\ExportSiteCommand;
use Gadya\Cms\Console\ExportSiteContentCommand;
use Gadya\Cms\Console\FetchSearchConsoleCommand;
use Gadya\Cms\Console\FixQualityCommand;
use Gadya\Cms\Console\ImportLegacyContentCommand;
use Gadya\Cms\Console\ImportLegacyMediaCommand;
use Gadya\Cms\Console\ImportSiteCommand;
use Gadya\Cms\Console\InstallCommand;
use Gadya\Cms\Console\MakeEditorCommand;
use Gadya\Cms\Console\MakeMediaVariantsCommand;
use Gadya\Cms\Console\MakePageTemplateCommand;
use Gadya\Cms\Console\PruneActivityCommand;
use Gadya\Cms\Console\PruneAnalyticsCommand;
use Gadya\Cms\Console\PruneTrashCommand;
use Gadya\Cms\Console\PublishDueCommand;
use Gadya\Cms\Console\ResetPasswordCommand;
use Gadya\Cms\Console\SendAnalyticsDigestCommand;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Content\SlugPagePaths;
use Gadya\Cms\Contracts\ResolvesPagePaths;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Events\PageViewed;
use Gadya\Cms\Http\Middleware\AdvertiseDiscovery;
use Gadya\Cms\Http\Middleware\ComingSoon;
use Gadya\Cms\Http\Middleware\HandleRedirects;
use Gadya\Cms\Http\Middleware\NegotiateMarkdown;
use Gadya\Cms\Http\Middleware\NoStoreWhenEditing;
use Gadya\Cms\Http\Middleware\RecordMissingUrls;
use Gadya\Cms\Http\Middleware\TrackPageViews;
use Gadya\Cms\Livewire\MediaPicker;
use Gadya\Cms\Mail\BrandsOutgoingMail;
use Gadya\Cms\Mail\PortalTransport;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Models\Event as EventModel;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Models\Setting;
use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Observers\FlushRedirectMap;
use Gadya\Cms\Observers\InvalidatePublishedDocument;
use Gadya\Cms\Observers\RecordActivity;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\Maintenance;
use Gadya\Cms\Support\SiteContext;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
                FixQualityCommand::class,
                ResetPasswordCommand::class,
                DoctorCommand::class,
                ExportSiteContentCommand::class,
                ImportLegacyContentCommand::class,
                ImportLegacyMediaCommand::class,
                PruneAnalyticsCommand::class,
                SendAnalyticsDigestCommand::class,
                MakePageTemplateCommand::class,
                MakeMediaVariantsCommand::class,
                ExportSiteCommand::class,
                ImportSiteCommand::class,
                FetchSearchConsoleCommand::class,
                CheckPageSpeedCommand::class,
                AgentReadyCommand::class,
                PruneTrashCommand::class,
                PruneActivityCommand::class,
                PublishDueCommand::class,
                CheckLinksCommand::class,
                AuditCommand::class,
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
        $this->app->scoped(SharedSender::class);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/editor.php');

        if (config('gadya-cms.blog.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/blog.php');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/seo.php');

        if (config('gadya-cms.site_search.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/search.php');
        }

        if (config('gadya-cms.events.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/events.php');
        }

        if (config('gadya-cms.newsletter.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/newsletter.php');
        }

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/gadya-cms'),
            __DIR__.'/../resources/css' => resource_path('css/vendor/gadya-cms'),
        ], 'gadya-cms-assets');

        $this->configureMail();

        $this->registerBladeDirectives();
        $this->registerRateLimiters();
        $this->registerAbilityGates();

        $this->recordLastLogin();
        $this->registerBroadcastChannel();

        Page::observe(InvalidatePublishedDocument::class);
        Redirect::observe(FlushRedirectMap::class);

        /*
         * Every change a person makes to something they can see, noted.
         */
        if (config('gadya-cms.activity.enabled', true)) {
            foreach ([Page::class, Post::class, Media::class, EventModel::class, Term::class, Redirect::class, Subscriber::class] as $model) {
                $model::observe(RecordActivity::class);
            }
        }
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

        /*
         * Global rather than in the web group: an old address has no route,
         * so group middleware would never run for it - the router says 404
         * first.
         */
        $this->app->make(Kernel::class)->pushMiddleware(HandleRedirects::class);
        /*
         * In the web group, not global: it has to run after the session has
         * started, or it cannot tell that the person asking is signed in and
         * shuts the client out of her own panel. The cookie is written plain
         * so a shared link keeps working, and excused from decryption.
         */
        EncryptCookies::except(Maintenance::COOKIE);
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ComingSoon::class);
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', AdvertiseDiscovery::class);
        $this->app->make(Kernel::class)->pushMiddleware(RecordMissingUrls::class);
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', NoStoreWhenEditing::class);
        $this->app->make(Kernel::class)->prependMiddlewareToGroup('web', NegotiateMarkdown::class);
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', TrackPageViews::class);

    }

    /**
     * Sending through Gadya Media, for a site with no mail service of its
     * own: the `gadya` mailer hands the message to the portal, which sends
     * it. Wired when something first asks for the mailer rather than on
     * every request, so a page that sends nothing never looks the pairing
     * up; the listener decides for itself, and only runs when a message is
     * actually on its way.
     */
    private function configureMail(): void
    {
        $this->app->afterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend(
                SharedSender::MAILER,
                fn (array $config) => new PortalTransport($this->app->make(PortalClient::class), $this->app->make(SharedSender::class)),
            );

            config(['mail.mailers.'.SharedSender::MAILER => ['transport' => SharedSender::MAILER]]);

            $sender = $this->app->make(SharedSender::class);

            if (! $sender->enabled()) {
                return;
            }

            config([
                'mail.default' => SharedSender::MAILER,
                'mail.from.address' => $sender->address(),
                'mail.from.name' => $sender->name(),
            ]);
        });

        Event::listen(MessageSending::class, BrandsOutgoingMail::class);
    }

    /**
     * The directives the site's own templates use to mark content as
     * editable and to resolve an image reference to a URL.
     */
    private function registerBladeDirectives(): void
    {
        Blade::directive('siteImage', fn (string $expression): string => "<?php echo e(app(\Gadya\Cms\Content\SiteImage::class)->url({$expression})); ?>");
        Blade::directive('siteFocus', fn (string $expression): string => "<?php echo e(app(\\Gadya\\Cms\\Content\\SiteImage::class)->focus({$expression})); ?>");
        Blade::directive('siteSrcset', fn (string $expression): string => "<?php echo e(app(\\Gadya\\Cms\\Content\\SiteImage::class)->srcset({$expression})); ?>");
        Blade::directive('siteThumbnail', fn (string $expression): string => "<?php echo e(app(\Gadya\Cms\Content\SiteImage::class)->thumbnailUrl({$expression})); ?>");
        Blade::directive('editable', fn (string $expression): string => "<?php echo app(\Gadya\Cms\Editor\EditContext::class)->attributes({$expression}); ?>");
        Blade::directive('editableGlobal', fn (string $expression): string => "<?php echo app(\Gadya\Cms\Editor\EditContext::class)->globalAttributes({$expression}); ?>");
        Blade::directive('editableFor', fn (string $expression): string => "<?php app(\Gadya\Cms\Editor\EditContext::class)->for({$expression}); ?>");
        /*
         * Both take an optional array of overrides, so `@cmsSearchForm` and
         * `@cmsSearchForm(['label' => 'Find a party place'])` both work.
         */
        Blade::directive('cmsSearchForm', function (string $expression): string {
            $expression = trim($expression) === '' ? '[]' : $expression;

            return "<?php echo view('gadya-cms::search.form', array_merge(['label' => 'Search this site', 'placeholder' => 'What are you looking for?'], (array) ({$expression})))->render(); ?>";
        });

        Blade::directive('cmsNewsletterForm', function (string $expression): string {
            $expression = trim($expression) === '' ? '[]' : $expression;

            return "<?php echo view('gadya-cms::forms.newsletter', array_merge(['label' => (string) config('gadya-cms.newsletter.label', 'Get our news by email'), 'button' => (string) config('gadya-cms.newsletter.button', 'Sign up'), 'honeypot' => (string) config('gadya-cms.forms.honeypot', 'website')], (array) ({$expression})))->render(); ?>";
        });
        Blade::directive('cmsForm', fn (string $expression): string => "<?php echo view('gadya-cms::forms.fields', ['form' => {$expression}, 'honeypot' => (string) config('gadya-cms.forms.honeypot', 'website')])->render(); ?>");
        Blade::directive('cmsFormStatus', fn (string $expression): string => "<?php echo view('gadya-cms::forms.status', ['form' => {$expression}])->render(); ?>");
        Blade::directive('gadyaBuiltBy', function (string $expression): string {
            $expression = trim($expression) === '' ? '[]' : $expression;

            return "<?php echo app(\\Gadya\\Cms\\Brand\\BuiltBy::class)->render({$expression}); ?>";
        });
        Blade::directive('cmsSeo', fn (string $expression): string => "<?php echo app(\\Gadya\\Cms\\Seo\\SeoHead::class)->render({$expression})->render(); ?>");
        Blade::directive('cmsToolbar', fn (): string => "<?php if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled()) { echo view('gadya-cms::editor.toolbar')->render(); } elseif (app(\Gadya\Cms\Editor\EditContext::class)->isPreviewing()) { echo view('gadya-cms::editor.preview-bar')->render(); } ?>");
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

    /**
     * One gate per ability, on top of the application's own content gate:
     * a person must be allowed into the CMS at all, and then allowed to do
     * this. An application that defines a gate of the same name first
     * keeps its own.
     */
    private function registerAbilityGates(): void
    {
        foreach (Abilities::all() as $ability) {
            if (Gate::has(Abilities::gate($ability))) {
                continue;
            }

            Gate::define(Abilities::gate($ability), fn ($user): bool => $user->can((string) config('gadya-cms.gate', 'manage-content'))
                && app(Abilities::class)->allows($user, $ability));
        }
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('gadya-cms-inline', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('gadya-cms-forms', fn (Request $request): Limit => Limit::perMinute(6)->by($request->ip()));

        RateLimiter::for('gadya-cms-events', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
    }
}
