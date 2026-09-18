@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <section class="cms-events">
        <header class="cms-events__header">
            <h1 class="cms-events__heading">{{ $page['heading'] }}</h1>
            @if ($page['description'] !== '')
                <p class="cms-events__lede">{{ $page['description'] }}</p>
            @endif
            <p class="cms-events__subscribe">
                <a href="{{ route('gadya-cms.events.calendar') }}">Add these to your calendar</a>
            </p>
        </header>

        @if ($upcoming->isEmpty())
            <p class="cms-events__empty">Nothing in the diary just now. Check back soon.</p>
        @endif

        <ol class="cms-events__list">
            @foreach ($upcoming as $event)
                <li class="cms-event-card">
                    @if ($event->image)
                        <a class="cms-event-card__image" href="{{ url($event->publicPath()) }}">
                            <img src="@siteImage($event->image, 480)" alt="{{ $event->hero_alt }}" loading="lazy">
                        </a>
                    @endif
                    <div class="cms-event-card__body">
                        <p class="cms-event-card__when">
                            <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->when() }}</time>
                        </p>
                        <h2 class="cms-event-card__title"><a href="{{ url($event->publicPath()) }}">{{ $event->title }}</a></h2>
                        @if ($event->location)
                            <p class="cms-event-card__where">{{ $event->location }}</p>
                        @endif
                        @if ($event->summary)
                            <p class="cms-event-card__summary">{{ $event->summary }}</p>
                        @endif
                        @if ($event->price)
                            <p class="cms-event-card__price">{{ $event->price }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($past->isNotEmpty())
            <section class="cms-events__past" aria-labelledby="cms-events-past">
                <h2 id="cms-events-past">Already happened</h2>
                <ul>
                    @foreach ($past as $event)
                        <li><a href="{{ url($event->publicPath()) }}">{{ $event->title }}</a> — {{ $event->starts_at->format('j F Y') }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </section>
@endsection
