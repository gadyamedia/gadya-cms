<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Search\SiteSearch;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    public function __invoke(Request $request, SiteSearch $search, SiteContentRepository $repository, PublicDocument $public, EditContext $editor): View
    {
        $editor->boot();

        $query = Str::limit(trim((string) $request->query('q', '')), 120, '');
        $results = $query === '' ? collect() : $search->for($query);

        $this->record($request, $query, $results->count());

        return view('gadya-cms::search.results', [
            'query' => $query,
            'results' => $results,
            'site' => $public->from($repository->forRequest()),
            'page' => [
                'title' => $query === '' ? 'Search' : 'Search: '.$query,
                'heading' => $query === '' ? 'Search this site' : 'Results for ‘'.$query.'’',
                'description' => '',
                'seo' => ['noindex' => true],
            ],
        ]);
    }

    /**
     * What people look for here - especially what they look for and do not
     * find - says more about missing content than any other number on the
     * site, so it is counted like any other thing a visitor does.
     */
    private function record(Request $request, string $query, int $results): void
    {
        if ($query === '' || ! config('gadya-cms.analytics.enabled', true) || VisitorFingerprint::isBot($request)) {
            return;
        }

        rescue(fn () => AnalyticsEvent::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'name' => 'site_search',
            'path' => '/'.trim((string) config('gadya-cms.site_search.path', 'search'), '/'),
            'visitor_hash' => VisitorFingerprint::hash($request),
            'metadata' => ['term' => Str::lower($query), 'results' => (string) $results],
            'created_at' => now(),
        ]), report: false);
    }
}
