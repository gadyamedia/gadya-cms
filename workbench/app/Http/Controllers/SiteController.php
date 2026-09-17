<?php

namespace Workbench\App\Http\Controllers;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * The whole public site in one controller - which is all a site on this
 * package needs, because the pages are data.
 */
class SiteController extends Controller
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PublicDocument $public,
        private readonly EditContext $editor,
        private readonly PageRegistry $registry,
    ) {}

    public function show(string $slug = 'home'): View|RedirectResponse
    {
        $this->editor->boot();

        $site = $this->public->from($this->repository->forRequest());
        $page = $site['pages'][$slug] ?? null;

        if (! is_array($page)) {
            $destination = $this->registry->resolveRedirect($slug);

            abort_if($destination === null, 404);

            return redirect()->to($this->registry->publicPathFor($destination, $site), 301);
        }

        abort_if($this->registry->isHidden($page) && ! $this->editor->showsDraft(), 404);

        $this->editor->for("pages.{$slug}");

        return view('pages.show', ['page' => $page, 'site' => $site, 'slug' => $slug]);
    }
}
