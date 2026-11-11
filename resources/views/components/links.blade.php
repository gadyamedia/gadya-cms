@props([
    /* Which list: "header", "footer", or any name the site uses. Stored at `links.{list}`. */
    'list',
    /* The site document, as the template has it. */
    'site' => [],
    /* Classes for each link, to match the links around them. */
    'linkClass' => '',
])

{{--
    Links the client adds herself - a waiver, a social page - wherever the
    template puts this: the header, the footer. Visitors see only finished
    links with a safe address; while editing, each shows where it goes so
    it can be changed, with a way to remove it and to add another.
--}}
@php
    $editing = app(\Gadya\Cms\Editor\EditContext::class)->isEnabled();
    $links = is_array($site['links'][$list] ?? null) ? $site['links'][$list] : [];
@endphp

<x-gadya-cms::region :name="'links.'.$list">
    @foreach ($links as $index => $link)
        @php
            $href = \Gadya\Cms\Support\SafeLink::href($link['url'] ?? null);
            $label = trim((string) ($link['label'] ?? ''));
        @endphp

        @if ($editing)
            <span class="gadya-cms-link-item">
                <a class="{{ $linkClass }}" href="{{ $href ?? '#' }}" @editableGlobal("links.{$list}.{$index}.label")>{{ $label }}</a>
                <span class="gadya-cms-link-item__url" data-cms-placeholder="Paste where it goes" @editableGlobal("links.{$list}.{$index}.url")>{{ $link['url'] ?? '' }}</span>
                @if (($link['url'] ?? '') !== '' && $href === null)
                    <span class="gadya-cms-link-item__warning">That address won't work as a link</span>
                @endif
                <button class="gadya-cms-link-item__remove" type="button" data-cms-link-remove="{{ $list }}" data-cms-link-index="{{ $index }}" aria-label="Remove the {{ $label }} link">×</button>
            </span>
        @elseif ($label !== '' && $href !== null)
            <a class="{{ $linkClass }}" href="{{ $href }}" @if (\Gadya\Cms\Support\SafeLink::isExternal($href)) target="_blank" rel="noopener" @endif>{{ $label }}</a>
        @endif
    @endforeach

    @if ($editing)
        <button class="gadya-cms-link-add" type="button" data-cms-link-add="{{ $list }}">+ Add link</button>
    @endif
</x-gadya-cms::region>
