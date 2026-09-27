@props([
    'label' => 'Today',
])

@php
    $hours = app(\Gadya\Cms\Hours\BusinessHours::class)->current();
    $today = \Carbon\CarbonImmutable::now($hours->timezone());
    $exception = $hours->exceptionOn($today);
@endphp

@if ($hours->isSet())
    <p {{ $attributes->class(['cms-todays-hours', 'cms-todays-hours--special' => $exception !== null]) }}>
        <span class="cms-todays-hours__label">{{ $label }}</span>
        <span class="cms-todays-hours__times">{{ \Gadya\Cms\Hours\OpeningHours::describe($hours->rangesOn($today)) }}</span>@if ($exception['label'] ?? null)
        <span class="cms-todays-hours__reason">({{ $exception['label'] }})</span>@endif
    </p>
@endif
