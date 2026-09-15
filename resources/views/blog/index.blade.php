@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <section class="cms-blog">
        <header class="cms-blog__header">
            <h1 class="cms-blog__heading">{{ $page['heading'] }}</h1>
            @if ($page['description'] !== '')
                <p class="cms-blog__lede">{{ $page['description'] }}</p>
            @endif
        </header>

        @if ($posts->isEmpty())
            <p class="cms-blog__empty">Nothing here yet. Check back soon.</p>
        @endif

        <div class="cms-blog__grid">
            @foreach ($posts as $post)
                <article class="cms-blog__card">
                    @if ($post->image)
                        <a class="cms-blog__card-image" href="{{ url($post->publicPath()) }}">
                            <img src="@siteImage($post->image)" alt="{{ $post->hero_alt }}" loading="lazy">
                        </a>
                    @endif
                    <div class="cms-blog__card-body">
                        <p class="cms-blog__meta">
                            <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->format('F j, Y') }}</time>
                            @if ($post->reading_time) · {{ $post->reading_time }} @endif
                        </p>
                        <h2 class="cms-blog__card-title"><a href="{{ url($post->publicPath()) }}">{{ $post->title }}</a></h2>
                        @if ($post->excerpt)
                            <p class="cms-blog__excerpt">{{ $post->excerpt }}</p>
                        @endif
                        <a class="cms-blog__more" href="{{ url($post->publicPath()) }}">Read more</a>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="cms-blog__pagination">{{ $posts->links() }}</div>
    </section>
@endsection
