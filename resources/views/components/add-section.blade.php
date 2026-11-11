@props([
    /* Where the new section goes: 0 is before the first, the section count is after the last. */
    'position',
])

{{--
    "+ Add a section here", between two sections of the page being edited.
    It opens the design picker the toolbar draws. Nothing for visitors.
--}}
@php
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
    $page = str_starts_with((string) $editor->basePath(), 'pages.') ? substr((string) $editor->basePath(), 6) : null;
@endphp

@if ($editor->isEnabled() && $page !== null)
    <div {{ $attributes->class(['gadya-cms-section-add']) }}>
        <button type="button" data-cms-section-add="{{ (int) $position }}" data-cms-page="{{ $page }}">+ Add a section here</button>
    </div>
@endif
