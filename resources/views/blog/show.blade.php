@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <article class="cms-article">
        <header class="cms-article__header">
            <p class="cms-article__meta">
                <a href="{{ url('/'.trim(config('gadya-cms.blog.prefix', 'blog'), '/')) }}">{{ $page['index_label'] }}</a>
                · <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->format('F j, Y') }}</time>
                @if ($post->reading_time) · {{ $post->reading_time }} @endif
            </p>
            <h1 class="cms-article__title">{{ $post->title }}</h1>
            @if ($post->excerpt)
                <p class="cms-article__lede">{{ $post->excerpt }}</p>
            @endif
        </header>

        @if ($post->image)
            <figure class="cms-article__figure">
                <img src="@siteImage($post->image)" alt="{{ $post->hero_alt }}">
            </figure>
        @endif

        <div class="cms-article__body">
            {!! $post->content !!}
        </div>

        @if (is_array($post->faq) && $post->faq !== [])
            <section class="cms-article__faq" aria-labelledby="cms-article-faq">
                <h2 id="cms-article-faq">Questions people ask</h2>
                @foreach ($post->faq as $item)
                    <details class="cms-article__faq-item">
                        <summary>{{ $item['question'] ?? '' }}</summary>
                        <p>{{ $item['answer'] ?? '' }}</p>
                    </details>
                @endforeach
            </section>

            <script type="application/ld+json">{!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'] ?? '',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer'] ?? ''],
                ], $post->faq),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
    </article>
@endsection
