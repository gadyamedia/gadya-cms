<?php

namespace Gadya\Cms\Filament;

use Composer\InstalledVersions;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Gadya\Cms\Content\PanelBrand;
use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Filament\Pages\AiSettings;
use Gadya\Cms\Filament\Pages\ArticleGenerator;
use Gadya\Cms\Filament\Pages\Blocks;
use Gadya\Cms\Filament\Pages\Dashboard;
use Gadya\Cms\Filament\Pages\Emails;
use Gadya\Cms\Filament\Pages\GetFound;
use Gadya\Cms\Filament\Pages\Globals;
use Gadya\Cms\Filament\Pages\Navigation;
use Gadya\Cms\Filament\Pages\SearchSettings;
use Gadya\Cms\Filament\Pages\SiteDetails;
use Gadya\Cms\Filament\Pages\SiteStatus;
use Gadya\Cms\Filament\Pages\ThemeSettings;
use Gadya\Cms\Filament\Resources\Activity\ActivityResource;
use Gadya\Cms\Filament\Resources\BrokenLinks\BrokenLinkResource;
use Gadya\Cms\Filament\Resources\Comments\CommentResource;
use Gadya\Cms\Filament\Resources\Events\EventResource;
use Gadya\Cms\Filament\Resources\Media\MediaResource;
use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Filament\Resources\Posts\PostResource;
use Gadya\Cms\Filament\Resources\Redirects\RedirectResource;
use Gadya\Cms\Filament\Resources\Revisions\RevisionResource;
use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Filament\Resources\Subscribers\SubscriberResource;
use Gadya\Cms\Filament\Resources\Terms\TermResource;
use Gadya\Cms\Filament\Resources\Users\UserResource;
use Gadya\Connect\Filament\GadyaConnectPlugin;
use Gadya\Connect\Filament\Pages\GadyaSupport;
use Gadya\Connect\Filament\Pages\GetHelp;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;

class GadyaCmsPlugin implements Plugin
{
    protected bool $hasAnalytics = true;

    protected bool $hasTeam = true;

    protected bool $hasBrand = true;

    protected bool $hasBlog = true;

    protected bool $hasAi = true;

    protected bool $hasRedirects = true;

    protected bool $hasForms = true;

    protected bool $hasSearch = true;

    protected bool $hasNewsletter = true;

    protected bool $hasEvents = true;

    protected bool $hasProfile = true;

    protected bool $warnsAboutUnsavedChanges = true;

    protected bool $showsPoweredBy = true;

    protected ?string $contentNavigationGroup = 'Content';

    protected ?string $appearanceNavigationGroup = 'Appearance';

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The plugin as the current panel configured it.
     *
     * Falls back to a default instance rather than throwing: a resource may
     * be asked for its navigation group outside any panel - in a test, or a
     * console command - and that should answer rather than fail.
     */
    public static function get(): static
    {
        return rescue(
            fn (): Plugin => filament(app(static::class)->getId()),
            fn (): static => app(static::class),
            report: false,
        );
    }

    public function getId(): string
    {
        return 'gadya-cms';
    }

    /**
     * The dashboard and its visitor figures. A site that already has
     * analytics elsewhere can leave them out and keep the rest.
     */
    public function analytics(bool $condition = true): static
    {
        $this->hasAnalytics = $condition;

        return $this;
    }

    public function hasAnalytics(): bool
    {
        return $this->hasAnalytics;
    }

    /** Inviting people to help manage the site. */
    public function team(bool $condition = true): static
    {
        $this->hasTeam = $condition;

        return $this;
    }

    public function hasTeam(): bool
    {
        return $this->hasTeam;
    }

    /**
     * The panel's logo, colours and type. Off leaves the panel looking like
     * stock Filament, for an application that brands its own.
     */
    public function brand(bool $condition = true): static
    {
        $this->hasBrand = $condition;

        return $this;
    }

    public function hasBrand(): bool
    {
        return $this->hasBrand;
    }

    /**
     * Articles, written by hand or drafted by AI. Off for a site that is
     * pages only.
     */
    public function blog(bool $condition = true): static
    {
        $this->hasBlog = $condition;

        return $this;
    }

    public function hasBlog(): bool
    {
        return $this->hasBlog;
    }

    /**
     * Writing with AI: the settings screen, the generator and the buttons
     * beside every search snippet. Off leaves everything written by hand.
     */
    public function ai(bool $condition = true): static
    {
        $this->hasAi = $condition;

        return $this;
    }

    public function hasAi(): bool
    {
        return $this->hasAi;
    }

    /**
     * Search Console, PageSpeed and the agent-readiness score on the
     * dashboard, with the settings screen that connects them.
     */
    public function search(bool $condition = true): static
    {
        $this->hasSearch = $condition;

        return $this;
    }

    public function hasSearch(): bool
    {
        return $this->hasSearch;
    }

    /**
     * Somewhere for a person to change their own name and password,
     * without an administrator having to do it for them.
     */
    public function profile(bool $condition = true): static
    {
        $this->hasProfile = $condition;

        return $this;
    }

    public function hasProfile(): bool
    {
        return $this->hasProfile;
    }

    /**
     * Ask before leaving a screen with unsaved edits on it. The whole
     * point of a draft is that work is not lost, and a closed tab is the
     * one way it still could be.
     */
    public function unsavedChangesAlerts(bool $condition = true): static
    {
        $this->warnsAboutUnsavedChanges = $condition;

        return $this;
    }

    public function warnsAboutUnsavedChanges(): bool
    {
        return $this->warnsAboutUnsavedChanges;
    }

    /** The "powered by Gadya CMS" line and version number under every screen. */
    public function poweredBy(bool $condition = true): static
    {
        $this->showsPoweredBy = $condition;

        return $this;
    }

    public function showsPoweredBy(): bool
    {
        return $this->showsPoweredBy;
    }

    /**
     * Gadya Support and Get help, from gadya/connect, when it is installed:
     * the site's link to the Gadya Media portal and the way to ask for help.
     *
     * @return list<class-string>
     */
    public static function connectPages(): array
    {
        return array_values(array_filter(
            [GadyaSupport::class, GetHelp::class],
            fn (string $page): bool => class_exists($page),
        ));
    }

    /** The installed gadya/cms release, e.g. "0.4.6", or null when Composer cannot say. */
    public static function packageVersion(): ?string
    {
        $version = rescue(fn (): ?string => InstalledVersions::getPrettyVersion('gadya/cms'), null, report: false);

        return $version === null ? null : ltrim($version, 'v');
    }

    /** Open days, camps and classes, with a calendar file for them. */
    public function events(bool $condition = true): static
    {
        $this->hasEvents = $condition;

        return $this;
    }

    public function hasEvents(): bool
    {
        return $this->hasEvents;
    }

    /** The mailing list, its sign-up box and its export. */
    public function newsletter(bool $condition = true): static
    {
        $this->hasNewsletter = $condition;

        return $this;
    }

    public function hasNewsletter(): bool
    {
        return $this->hasNewsletter;
    }

    /** The inbox of what visitors sent through the site's forms. */
    public function forms(bool $condition = true): static
    {
        $this->hasForms = $condition;

        return $this;
    }

    public function hasForms(): bool
    {
        return $this->hasForms;
    }

    /** The table of old addresses and where they go now. */
    public function redirects(bool $condition = true): static
    {
        $this->hasRedirects = $condition;

        return $this;
    }

    public function hasRedirects(): bool
    {
        return $this->hasRedirects;
    }

    /** Where the CMS puts itself in a panel that has navigation of its own. */
    public function navigationGroups(?string $content = 'Content', ?string $appearance = 'Appearance'): static
    {
        $this->contentNavigationGroup = $content;
        $this->appearanceNavigationGroup = $appearance;

        return $this;
    }

    public function getContentNavigationGroup(): ?string
    {
        return $this->contentNavigationGroup;
    }

    public function getAppearanceNavigationGroup(): ?string
    {
        return $this->appearanceNavigationGroup;
    }

    public function register(Panel $panel): void
    {
        /*
         * The application may keep Livewire's asset auto-injection off so a
         * public site ships no Livewire JS to visitors. The panel cannot
         * work without the runtime - Filament's own scripts wait for
         * `livewire:init`, and without it nothing boots: every
         * `wire:loading` spinner stays visible forever and no table or
         * widget ever loads. Injecting through the panel's own render hooks
         * keeps this scoped to panel responses, rather than flipping a
         * process-wide flag that would leak into public requests under a
         * persistent worker.
         */
        $panel
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render('@livewireStyles'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => static::livewireScripts());

        if ($this->warnsAboutUnsavedChanges()) {
            $panel->unsavedChangesAlerts();
        }

        if (class_exists(GadyaConnectPlugin::class)) {
            GadyaConnectPlugin::addHelpButton($panel);
        }

        if ($this->showsPoweredBy()) {
            $panel->renderHook(PanelsRenderHook::FOOTER, fn (): View => view('gadya-cms::filament.powered-by', ['version' => static::packageVersion()]));
        }

        if ($this->hasProfile()) {
            $panel->profile(isSimple: false);
        }

        $panel
            ->resources(array_filter([
                PageResource::class,
                MediaResource::class,
                RevisionResource::class,
                $this->hasTeam() ? UserResource::class : null,
                $this->hasBlog() ? PostResource::class : null,
                $this->hasBlog() ? TermResource::class : null,
                $this->hasBlog() ? CommentResource::class : null,
                $this->hasEvents() ? EventResource::class : null,
                $this->hasRedirects() ? RedirectResource::class : null,
                $this->hasRedirects() ? BrokenLinkResource::class : null,
                config('gadya-cms.activity.enabled', true) ? ActivityResource::class : null,
                $this->hasForms() ? SubmissionResource::class : null,
                $this->hasNewsletter() ? SubscriberResource::class : null,
            ]))
            ->pages(array_filter([
                $this->hasAnalytics() ? Dashboard::class : null,
                ThemeSettings::class,
                Globals::class,
                Blocks::class,
                SiteDetails::class,
                Navigation::class,
                $this->hasAi() ? AiSettings::class : null,
                $this->hasSearch() ? SearchSettings::class : null,
                GetFound::class,
                SiteStatus::class,
                $this->hasForms() ? Emails::class : null,
                $this->hasAi() && $this->hasBlog() ? ArticleGenerator::class : null,
                ...static::connectPages(),
            ]));

        if (! $this->hasBrand()) {
            return;
        }

        $panel
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): View => view('gadya-cms::filament.brand-head', static::brandTokens()))
            ->brandName((string) config('gadya-cms.brand.name'))
            ->brandLogo(fn (): ?string => static::brandLogo())
            ->brandLogoHeight((string) config('gadya-cms.brand.logo_height', '2.75rem'))
            ->favicon(fn (): ?string => static::brandLogo())
            ->font((string) config('gadya-cms.brand.fonts.body', 'Inter'))
            ->colors([
                'primary' => Color::hex((string) config('gadya-cms.brand.primary', '#9f12c7')),
                'info' => Color::hex((string) config('gadya-cms.brand.secondary', '#f54fa3')),
            ]);
    }

    public function boot(Panel $panel): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            fn (): View => view('gadya-cms::filament.view-site-link'),
        );
    }

    /**
     * Livewire's script tag, with the cache key widened to cover the build
     * variant.
     *
     * Livewire versions that URL by its own release hash alone, which does
     * not move when `csp_safe` changes even though the bundle it serves is
     * completely different - and the asset is sent with a year of
     * `max-age`. Switching that setting would otherwise leave every browser
     * that had already loaded the panel on the old bundle until someone
     * thought to hard-refresh.
     */
    protected static function livewireScripts(): string
    {
        $variant = config('livewire.csp_safe') ? 'csp' : 'std';

        return (string) preg_replace(
            '~(livewire(?:\.min)?\.js\?id=[A-Za-z0-9]+)~',
            '$1-'.$variant,
            Blade::render('@livewireScripts'),
        );
    }

    /**
     * The brand mark, resolved through the photo library so it follows the
     * image if it is ever re-uploaded. Null leaves Filament showing the
     * brand name as text.
     *
     * Deliberately resolved from a closure at render time rather than when
     * the panel is configured: the library is memoised for the life of the
     * request, and reading it during registration would freeze it before
     * anything else had a chance to touch it.
     */
    public static function brandLogo(): ?string
    {
        $logo = app(PanelBrand::class)->logo();

        return $logo === null ? null : app(SiteImage::class)->url($logo);
    }

    /**
     * @return array<string, string|null>
     */
    public static function brandTokens(): array
    {
        return app(PanelBrand::class)->tokens();
    }
}
