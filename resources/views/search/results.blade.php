@extends(config('gadya-cms.blog.layout', 'layouts.app'))

@section('content')
    <section class="cms-search">
        <h1 class="cms-search__heading">{{ $page['heading'] }}</h1>

        @cmsSearchForm

        @if ($query !== '')
            <p class="cms-search__count">
                {{ $results->isEmpty() ? 'Nothing matched.' : $results->count().' '.Str::plural('result', $results->count()) }}
            </p>
        @endif

        @if ($query !== '' && $results->isEmpty())
            <p class="cms-search__empty">Try a shorter phrase, or fewer words.</p>
        @endif

        <ol class="cms-search__results">
            @foreach ($results as $result)
                <li class="cms-search__result">
                    <p class="cms-search__kind">{{ $result['kind'] }}</p>
                    <h2 class="cms-search__title"><a href="{{ $result['url'] }}">{{ $result['title'] }}</a></h2>
                    @if ($result['snippet'] !== '')
                        <p class="cms-search__snippet">{{ $result['snippet'] }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endsection
