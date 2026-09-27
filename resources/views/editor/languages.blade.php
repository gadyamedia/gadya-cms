@php
    $current = $locales->current();
@endphp

<nav class="gadya-cms-toolbar__languages" aria-label="Edit in another language">
    @foreach ($locales->alternates(request()->getPathInfo()) as $locale => $url)
        @if ($locale === $current)
            <span class="gadya-cms-toolbar__language-current" aria-current="true" lang="{{ $locale }}">{{ $locales->name($locale) }}</span>
        @else
            <a class="gadya-cms-link" href="{{ $url }}" hreflang="{{ $locale }}" lang="{{ $locale }}">{{ $locales->name($locale) }}</a>
        @endif
    @endforeach
</nav>
