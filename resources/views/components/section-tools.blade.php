@props([
    'index',
    'first' => false,
    'last' => false,
])

{{--
    Move a section up or down, or remove it, from the page. Put it at the
    top of each section in the loop; visitors never see it.
--}}
@php
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
    $page = str_starts_with((string) $editor->basePath(), 'pages.') ? substr((string) $editor->basePath(), 6) : null;
@endphp

@if ($editor->isEnabled() && $page !== null)
    <div {{ $attributes->class(['gadya-cms-section-tools']) }} role="group" aria-label="Section {{ (int) $index + 1 }}">
        <span>Section {{ (int) $index + 1 }}</span>
        <button type="button" data-cms-section-move="-1" data-cms-section-index="{{ (int) $index }}" data-cms-page="{{ $page }}" @disabled($first)>↑ Move up</button>
        <button type="button" data-cms-section-move="1" data-cms-section-index="{{ (int) $index }}" data-cms-page="{{ $page }}" @disabled($last)>↓ Move down</button>
        <button type="button" class="gadya-cms-section-tools__remove" data-cms-section-remove="{{ (int) $index }}" data-cms-page="{{ $page }}">Remove section</button>
    </div>
@endif
