<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Models\Comment;
use Gadya\Cms\Notifications\CommentPosted;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Notification;

class CommentController extends Controller
{
    public function store(Request $request, string $slug, BlogRepository $blog, SiteContext $siteContext): JsonResponse|RedirectResponse
    {
        abort_unless(config('gadya-cms.blog.comments.enabled', false), 404);

        $post = $blog->findLive($slug);

        abort_if($post === null, 404);

        $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');

        if ($honeypot !== '' && filled($request->input($honeypot))) {
            return $this->done($request, $post->publicPath());
        }

        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:120'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $comment = Comment::query()->create([
            ...$data,
            'site_id' => $siteContext->id(),
            'post_id' => $post->getKey(),
            'visitor_hash' => VisitorFingerprint::hash($request),
            'status' => config('gadya-cms.blog.comments.moderate', true) ? Comment::PENDING : Comment::APPROVED,
            'approved_at' => config('gadya-cms.blog.comments.moderate', true) ? null : now(),
        ]);

        $notify = array_values(array_filter((array) config('gadya-cms.blog.comments.notify', []), 'is_string'));

        if ($notify !== []) {
            rescue(fn () => Notification::route('mail', $notify)->notify(new CommentPosted($comment)), report: true);
        }

        return $this->done($request, $post->publicPath());
    }

    private function done(Request $request, string $path): JsonResponse|RedirectResponse
    {
        $message = config('gadya-cms.blog.comments.moderate', true)
            ? (string) config('gadya-cms.blog.comments.pending_message', 'Thank you. Your comment will appear once it has been read.')
            : (string) config('gadya-cms.blog.comments.posted_message', 'Thank you.');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->to($path.'#comments')->with('gadya-cms.comment', $message);
    }
}
