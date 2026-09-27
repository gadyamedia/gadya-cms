{{--
    The same page in each of the site's languages. Renders nothing on a
    site with one. Classes only, no styles: `cms-languages`,
    `cms-languages__link` and `cms-languages__link--current`.

        <x-gadya-cms::language-switcher />
        @cmsLanguageSwitcher(['label' => 'Idioma'])
--}}
@php
    $locales = app(\Gadya\Cms\Localisation\Locales::class);
    $label = ($label ?? null) ?? (isset($attributes) ? $attributes->get('label') : null) ?? 'Language';
@endphp
@if ($locales->isMultilingual())
    <nav class="cms-languages" aria-label="{{ $label }}">
        @foreach ($locales->alternates(request()->getPathInfo()) as $code => $href)
            @if ($code === $locales->current())
                <span class="cms-languages__link cms-languages__link--current" lang="{{ $code }}" aria-current="true">{{ $locales->name($code) }}</span>
            @else
                <a class="cms-languages__link" href="{{ $href }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}" hreflang="{{ $code }}" lang="{{ $code }}">{{ $locales->name($code) }}</a>
            @endif
        @endforeach
    </nav>
@endif
