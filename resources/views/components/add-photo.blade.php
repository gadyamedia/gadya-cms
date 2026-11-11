@props([
    /* The gallery section's index on the page being edited. */
    'section',
])

{{--
    "+ Add a photo" at the end of a gallery: it adds an empty slot and the
    photo library fills it. Draw empty slots with `@editable(..., 'image')`
    as something to click while editing, and skip them for visitors.
--}}
@php
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
@endphp

@if ($editor->isEnabled() && str_starts_with((string) $editor->basePath(), 'pages.'))
    <button {{ $attributes->class(['gadya-cms-photo-slot']) }} type="button" data-cms-gallery-add="{{ $editor->basePath() }}.sections.{{ (int) $section }}">+ Add a photo</button>
@endif
