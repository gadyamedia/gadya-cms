@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <section class="cms-blog">
        <header class="cms-blog__header">
            @if ($term ?? null)
                <p class="cms-blog__crumb"><a href="{{ url('/'.trim(config('gadya-cms.blog.prefix', 'blog'), '/')) }}">{{ $page['index_label'] }}</a></p>
            @endif
            <h1 class="cms-blog__heading">{{ $page['heading'] }}</h1>
            @if ($page['description'] !== '')
                <p class="cms-blog__lede">{{ $page['description'] }}</p>
            @endif
        </header>

        @if (($categories ?? collect())->isNotEmpty())
            <nav class="cms-blog__terms" aria-label="Categories">
                <a @class(['cms-blog__term', 'cms-blog__term--current' => ! ($term ?? null)]) href="{{ url('/'.trim(config('gadya-cms.blog.prefix', 'blog'), '/')) }}">Everything</a>
                @foreach ($categories as $category)
                    <a @class(['cms-blog__term', 'cms-blog__term--current' => ($term ?? null)?->is($category)]) href="{{ url($category->publicPath()) }}">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        @if ($posts->isEmpty())
            <p class="cms-blog__empty">Nothing here yet. Check back soon.</p>
        @endif

        <div class="cms-blog__grid">
            @foreach ($posts as $post)
                <article class="cms-blog__card">
                    @if ($post->image)
                        <a class="cms-blog__card-image" href="{{ url($post->publicPath()) }}">
                            <img src="@siteImage($post->image, 480)" srcset="@siteSrcset($post->image)" sizes="(max-width: 40rem) 100vw, 20rem" alt="{{ $post->hero_alt }}" loading="lazy">
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
