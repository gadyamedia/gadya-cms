<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Seo\Markdown;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * The articles, on the public site, in the application's own layout.
 *
 * Optional: an application with its own blog templates leaves these
 * routes off and reads from the BlogRepository itself.
 */
class BlogController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blog,
        private readonly SiteContentRepository $repository,
        private readonly PublicDocument $publicDocument,
        private readonly EditContext $editContext,
    ) {}

    public function index(): View
    {
        return view('gadya-cms::blog.index', [
            'posts' => $this->blog->live(),
            'term' => null,
            'categories' => $this->blog->termsInUse(),
            'site' => $this->site(),
            'page' => [
                'title' => (string) config('gadya-cms.blog.title', 'Blog'),
                'heading' => (string) config('gadya-cms.blog.heading', config('gadya-cms.blog.title', 'Blog')),
                'description' => (string) config('gadya-cms.blog.description', ''),
                'index_label' => (string) config('gadya-cms.blog.title', 'Blog'),
            ],
        ]);
    }

    /**
     * Every article on a shelf. The same template as the index, with the
     * term's own name and description at the top, so a category reads as a
     * page in its own right rather than a filtered list.
     */
    public function category(string $slug): View
    {
        return $this->term(Term::CATEGORY, $slug);
    }

    public function tag(string $slug): View
    {
        return $this->term(Term::TAG, $slug);
    }

    /*
     * One action per taxonomy rather than one with the taxonomy as a route
     * default: Laravel hands route parameters to a controller method by
     * position, not by name, so a default would arrive in the wrong one.
     */
    private function term(string $taxonomy, string $slug): View
    {
        $term = $this->blog->findTerm($taxonomy, $slug);

        abort_if($term === null, 404);

        return view('gadya-cms::blog.index', [
            'posts' => $this->blog->inTerm($term),
            'term' => $term,
            'categories' => $this->blog->termsInUse(),
            'site' => $this->site(),
            'page' => [
                'title' => $term->name,
                'heading' => $term->name,
                'description' => (string) $term->description,
                'index_label' => (string) config('gadya-cms.blog.title', 'Blog'),
            ],
        ]);
    }

    public function show(string $slug): View|Response
    {
        $this->editContext->boot();

        $post = $this->editContext->showsDraft()
            ? $this->blog->findAny($slug)
            : $this->blog->findLive($slug);

        abort_if($post === null, 404);

        if (Markdown::wanted(request())) {
            return response(app(Markdown::class)->forPost($post, request()->url()))
                ->header('Content-Type', 'text/markdown; charset=utf-8')
                ->header('Vary', 'Accept');
        }

        return view('gadya-cms::blog.show', [
            'post' => $post,
            'related' => $this->blog->related($post),
            'site' => $this->site(),
            'page' => [
                'title' => $post->meta_title ?: $post->title,
                'heading' => $post->title,
                'description' => (string) ($post->meta_description ?: $post->excerpt),
                'index_label' => (string) config('gadya-cms.blog.title', 'Blog'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function site(): array
    {
        $this->editContext->boot();

        return $this->publicDocument->from($this->repository->forRequest());
    }
}
