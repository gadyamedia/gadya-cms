@props([
    /* The item, as the template has it. */
    'item' => [],
    /* The item's path relative to the page, e.g. "sections.2.items.0". */
    'path',
])

{{--
    An optional button on a card, text box or list item: "Sign the waiver",
    "Book open play". Visitors see it once it has words and a safe address;
    while editing, both are typed in place.
--}}
@php
    $editing = app(\Gadya\Cms\Editor\EditContext::class)->isEnabled();
    $label = trim((string) ($item['link_label'] ?? ''));
    $href = \Gadya\Cms\Support\SafeLink::href($item['link_url'] ?? null);
@endphp

@if ($editing)
    <span class="gadya-cms-item-button">
        <span {{ $attributes->class(['gadya-cms-item-button__label']) }} data-cms-placeholder="+ Add a button" @editable("{$path}.link_label")>{{ $label }}</span>
        <span class="gadya-cms-link-item__url" data-cms-placeholder="…and paste where it goes" @editable("{$path}.link_url")>{{ $item['link_url'] ?? '' }}</span>
        @if (($item['link_url'] ?? '') !== '' && $href === null)
            <span class="gadya-cms-link-item__warning">That address won't work as a link</span>
        @endif
    </span>
@elseif ($label !== '' && $href !== null)
    <a {{ $attributes }} href="{{ $href }}" @if (\Gadya\Cms\Support\SafeLink::isExternal($href)) target="_blank" rel="noopener" @endif>{{ $label }}</a>
@endif
