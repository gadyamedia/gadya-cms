@props([
    'openLabel' => 'Open now',
    'closedLabel' => 'Closed',
])

{{--
    Worked out when the page is drawn. A page kept in a cache for longer
    than a few minutes can say "Open now" after closing time; see
    docs/local-business.md.
--}}
@php
    $hours = app(\Gadya\Cms\Hours\BusinessHours::class)->current();
    $status = $hours->isSet() ? $hours->status() : null;
@endphp

@if ($status !== null)
    <p {{ $attributes->class([
        'cms-open-status',
        'cms-open-status--open' => $status['open'],
        'cms-open-status--closed' => ! $status['open'],
        'cms-open-status--closing-soon' => $status['closing_soon'],
    ]) }} data-open="{{ $status['open'] ? 'true' : 'false' }}">
        <span class="cms-open-status__state">{{ $status['open'] ? $openLabel : $closedLabel }}</span>@if ($status['detail'] !== '')<span class="cms-open-status__detail"> · {{ $status['detail'] }}</span>@endif
    </p>
@endif
