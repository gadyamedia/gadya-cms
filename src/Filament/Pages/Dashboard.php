<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Gadya\Cms\Analytics\AnalyticsReport;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;

/**
 * What the client sees first: how the site is doing, in her words.
 *
 * The numbers come from the site's own tables - no Google, no third-party
 * script, no cookie banner earned. That also means they are only ever as
 * good as this box, which is the trade being made deliberately.
 */
class Dashboard extends Page
{
    protected string $view = 'gadya-cms::filament.pages.dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = -2;

    /*
     * The panel's home page. Filament builds the route name from the class
     * rather than this, so it stays `pages.dashboard` and keeps working
     * wherever the panel links to itself.
     */
    protected static string $routePath = '/';

    /**
     * Serve at the panel root, so signing in lands on the dashboard rather
     * than bouncing through a redirect. The route name still comes from the
     * class, so it stays `pages.dashboard`.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    /**
     * A visit arriving over the socket re-renders the page, so the live
     * panel updates the moment someone lands. The view's poll stays as
     * well: it is what keeps the panel honest when Reverb is not running,
     * which is most of the time on a developer's machine.
     */
    #[On('echo-private:gadya-cms.analytics,.page.viewed')]
    public function refreshLive(): void
    {
        // Re-rendering is the refresh - every figure is computed on render.
    }

    /** Days of history the figures cover. */
    public int $days = 30;

    /**
     * @return array<int, int>
     */
    public function getRangeOptions(): array
    {
        return [7, 30, 90];
    }

    public function setRange(int $days): void
    {
        $this->days = in_array($days, $this->getRangeOptions(), true) ? $days : 30;
    }

    /**
     * The world map, when one is configured. Cached because the SVG is
     * large and never changes between requests.
     */
    public function getWorldMapProperty(): ?string
    {
        $path = config('gadya-cms.analytics.world_map');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        return Cache::remember(
            'gadya-cms.world-map',
            now()->addDay(),
            fn (): string => (string) file_get_contents($path),
        );
    }

    public function getReportProperty(): AnalyticsReport
    {
        return app(AnalyticsReport::class)->for($this->days);
    }

    /**
     * Page titles, so the panel can say "Decor" where the table only knows
     * "/decor".
     *
     * @return array<string, string>
     */
    public function getPageTitlesProperty(): array
    {
        $document = app(SiteContentRepository::class)->published();
        $registry = app(PageRegistry::class);
        $titles = [];

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (is_array($page)) {
                $titles[$registry->publicPathFor((string) $slug, $document)] = (string) ($page['title'] ?? $slug);
            }
        }

        return $titles;
    }

    /**
     * @return Collection<int, Revision>
     */
    public function getRecentPublishesProperty(): Collection
    {
        return Revision::query()->with('publisher')->latest('id')->limit(5)->get();
    }

    /**
     * Things worth telling the client about her own install, rather than
     * making her discover them when an upload silently fails.
     *
     * @return list<string>
     */
    public function getNoticesProperty(): array
    {
        $notices = [];

        $missing = app(ImageCapabilities::class)->missingBinaries();

        if ($missing !== []) {
            $notices[] = 'Photo optimisation is off: '.implode(', ', $missing).' not installed on the server. Uploads still work, they are just larger than they need to be.';
        }

        if (app(AnalyticsReport::class)->for(1)->headline()['views'] === 0) {
            $notices[] = 'No visits recorded yet today. Figures start filling in as soon as someone visits the site.';
        }

        return $notices;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.gate', 'manage-content')) ?? false;
    }
}
