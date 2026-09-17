<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
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
            'site' => $this->site(),
            'page' => [
                'title' => (string) config('gadya-cms.blog.title', 'Blog'),
                'heading' => (string) config('gadya-cms.blog.heading', config('gadya-cms.blog.title', 'Blog')),
                'description' => (string) config('gadya-cms.blog.description', ''),
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
