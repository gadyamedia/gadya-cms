@props([
    'caption' => 'Opening hours',
    /* Holidays and special days this many days ahead are listed under the table. */
    'upcoming' => null,
    'specialHeading' => 'Holiday hours',
])

@php
    $hours = app(\Gadya\Cms\Hours\BusinessHours::class)->current();
    $today = \Carbon\CarbonImmutable::now($hours->timezone());
    $todayKey = strtolower($today->locale('en')->format('D'));
    $special = $hours->upcomingExceptions($today, (int) ($upcoming ?? config('gadya-cms.hours.upcoming_days', 60)));
@endphp

@if ($hours->isSet())
    <div {{ $attributes->class(['cms-hours']) }}>
        <table class="cms-hours__table">
            @if ($caption)
                <caption class="cms-hours__caption">{{ $caption }}</caption>
            @endif
            <tbody>
                @foreach (\Gadya\Cms\Hours\OpeningHours::DAYS as $day => $name)
                    @php($ranges = $hours->regular()[$day])
                    <tr @class(['cms-hours__day', 'cms-hours__day--today' => $day === $todayKey, 'cms-hours__day--closed' => $ranges === []]) @if ($day === $todayKey) aria-current="date" @endif>
                        <th scope="row" class="cms-hours__name">{{ $name }}</th>
                        <td class="cms-hours__times">
                            @if ($ranges === [] || count($ranges) === 1 && $ranges[0][0] === $ranges[0][1] && $ranges[0][0] === '00:00')
                                {{ \Gadya\Cms\Hours\OpeningHours::describe($ranges) }}
                            @else
                                @foreach ($ranges as [$opens, $closes])
                                    <span class="cms-hours__range"><time datetime="{{ $opens }}">{{ \Gadya\Cms\Hours\OpeningHours::time($opens) }}</time> – <time datetime="{{ $closes === '24:00' ? '00:00' : $closes }}">{{ \Gadya\Cms\Hours\OpeningHours::time($closes) }}</time></span>@if (! $loop->last), @endif
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($special !== [])
            <div class="cms-hours__special">
                <p class="cms-hours__special-heading">{{ $specialHeading }}</p>
                <ul class="cms-hours__special-list" role="list">
                    @foreach ($special as $exception)
                        <li @class(['cms-hours__special-day', 'cms-hours__special-day--closed' => $exception['closed']])>
                            <time datetime="{{ $exception['date'] }}">{{ \Carbon\CarbonImmutable::parse($exception['date'])->locale('en')->format('l, F j') }}</time>@if ($exception['label']) <span class="cms-hours__special-label">({{ $exception['label'] }})</span>@endif:
                            {{ \Gadya\Cms\Hours\OpeningHours::describe($exception['ranges']) }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('gadya-cms::hours.edit-link')
    </div>
@else
    @include('gadya-cms::hours.edit-link')
@endif
