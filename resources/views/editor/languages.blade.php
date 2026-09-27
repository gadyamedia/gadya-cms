@php
    $translations = app(\Gadya\Cms\Localisation\Translations::class);
    $current = $locales->current();
    $key = app(\Gadya\Cms\Editor\EditContext::class)->basePath();
    $translating = $locales->isTranslating() && $key !== null;
    $row = $translating ? $translations->find($key, $current) : null;
    $canTranslate = $translating
        && auth()->user()?->can(\Gadya\Cms\Access\Abilities::gate(\Gadya\Cms\Access\Abilities::CONTENT))
        && app(\Gadya\Cms\Localisation\Translator::class)->available();
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

@if ($row?->needs_review)
    <form class="gadya-cms-toolbar__review" method="POST" action="{{ route('gadya-cms.translations.review') }}">
        @csrf
        <input type="hidden" name="key" value="{{ $key }}">
        <span>Machine translated - read it through and fix anything before it goes live.</span>
        <button class="gadya-cms-link" type="submit">Mark as reviewed</button>
    </form>
@endif

@if ($canTranslate)
    <form method="POST" action="{{ route('gadya-cms.translations.translate') }}">
        @csrf
        <input type="hidden" name="key" value="{{ $key }}">
        <button class="gadya-cms-link" type="submit">{{ $row === null ? 'Translate this page' : 'Translate again' }} into {{ $locales->englishName($current) }}</button>
    </form>
@endif
