@php
    $comments = $post->comments()->approved()->orderBy('created_at')->get();
    $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');
@endphp

<section class="cms-comments" id="comments" aria-labelledby="cms-comments-heading">
    <h2 id="cms-comments-heading">{{ $comments->count() === 0 ? 'Be the first to say something' : $comments->count().' '.Str::plural('comment', $comments->count()) }}</h2>

    @foreach ($comments as $comment)
        <article class="cms-comment">
            <p class="cms-comment__who">
                <strong>{{ $comment->author_name }}</strong>
                <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->format('j F Y') }}</time>
            </p>
            <p class="cms-comment__body">{!! nl2br(e($comment->body)) !!}</p>
        </article>
    @endforeach

    @if (session()->has('gadya-cms.comment'))
        <p class="cms-comments__message" role="status">{{ session('gadya-cms.comment') }}</p>
    @endif

    @php $bag = $errors ?? session('errors') ?? new \Illuminate\Support\ViewErrorBag; @endphp

    @if ($bag->any())
        <ul class="cms-comments__errors" role="alert">
            @foreach ($bag->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <form class="cms-comments__form" method="POST" action="{{ route('gadya-cms.blog.comments.store', $post->slug) }}">
        @csrf
        @if ($honeypot !== '')
            <div class="cms-form__trap" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                <label for="cms-comment-{{ $honeypot }}">Leave this empty</label>
                <input type="text" id="cms-comment-{{ $honeypot }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
            </div>
        @endif

        <label for="cms-comment-name">Your name</label>
        <input id="cms-comment-name" name="author_name" value="{{ old('author_name') }}" maxlength="120" required>

        <label for="cms-comment-email">Your email <span>(not shown)</span></label>
        <input id="cms-comment-email" name="author_email" type="email" value="{{ old('author_email') }}" maxlength="255">

        <label for="cms-comment-body">Your comment</label>
        <textarea id="cms-comment-body" name="body" rows="4" maxlength="2000" required>{{ old('body') }}</textarea>

        <button type="submit">Post it</button>
    </form>
</section>
