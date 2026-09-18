@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <article class="cms-event">
        <header class="cms-event__header">
            <p class="cms-event__crumb"><a href="{{ route('gadya-cms.events.index') }}">{{ config('gadya-cms.events.title', 'What’s on') }}</a></p>
            <h1 class="cms-event__title">{{ $event->title }}</h1>
            <p class="cms-event__when">
                <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->when() }}</time>
                @if ($event->hasFinished()) <span class="cms-event__over">This one has been and gone.</span> @endif
            </p>
            @if ($event->location)
                <p class="cms-event__where">{{ $event->location }}</p>
            @endif
            @if ($event->price)
                <p class="cms-event__price">{{ $event->price }}</p>
            @endif
        </header>

        @if ($event->image)
            <figure class="cms-event__figure">
                <img src="@siteImage($event->image, 1200)" srcset="@siteSrcset($event->image)" sizes="(max-width: 46rem) 100vw, 46rem" alt="{{ $event->hero_alt }}">
            </figure>
        @endif

        @if ($event->summary)
            <p class="cms-event__lede">{{ $event->summary }}</p>
        @endif

        <div class="cms-event__body">{!! $event->body !!}</div>

        <p class="cms-event__actions">
            @if ($event->booking_url && ! $event->hasFinished())
                <a class="cms-event__book" href="{{ $event->booking_url }}" data-analytics="booking_start" data-analytics-label="{{ $event->title }}">Book a place</a>
            @endif
            <a class="cms-event__calendar" href="{{ route('gadya-cms.events.calendar') }}">Add to your calendar</a>
        </p>
    </article>

    <script type="application/ld+json">{!! json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
