@props([
    /* A name unique on the page, e.g. "sections" or "links.footer". */
    'name',
])

{{--
    Part of the page the live editor redraws on its own after a change of
    shape (a section added, a card or link removed), so the client stays
    where she was instead of the whole page reloading. Visitors get the
    contents alone; editors get a wrapper that takes no room in the layout.
--}}
@if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled())
    <div data-cms-region="{{ $name }}" style="display: contents">{{ $slot }}</div>
@else
    {{ $slot }}
@endif
