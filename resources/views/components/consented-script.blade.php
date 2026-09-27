@props([
    /* necessary, analytics or marketing. */
    'category' => 'marketing',
    'src' => null,
])

{{--
    A third-party tag that waits for the visitor's say-so. It is written as
    type="text/plain", which a browser never runs, and the consent runtime
    turns it into a real script once its category is allowed - at once for
    a visitor who already agreed, or the moment she does.
--}}
@php
    $category = in_array($category, \Gadya\Cms\Privacy\Consent::categories(), true) ? $category : \Gadya\Cms\Privacy\Consent::MARKETING;
    $consent = app(\Gadya\Cms\Privacy\Consent::class);
    $settings = $consent->settings();
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $runsAsIs = $category === \Gadya\Cms\Privacy\Consent::NECESSARY
        || (! $settings['banner_enabled'] && ! ($category === \Gadya\Cms\Privacy\Consent::MARKETING && $settings['honour_gpc']));
@endphp

@php
    /* A tag's own type (type="module") is kept for when it wakes, never allowed to wake it early. */
    $ownType = $attributes->get('type');
    $tag = array_filter($runsAsIs
        ? ['type' => $ownType, 'src' => $src, 'nonce' => $nonce ?: null]
        : ['type' => 'text/plain', 'data-cms-consent' => $category, 'data-cms-src' => $src, 'data-cms-type' => $ownType, 'nonce' => $nonce ?: null], fn ($value): bool => $value !== null && $value !== '');
@endphp
<script {{ $attributes->except('type')->merge($tag) }}>{{ $slot }}</script>
@unless ($runsAsIs)
@once('gadya-cms-consent-runtime')
@include('gadya-cms::privacy.runtime')
@endonce
@endunless
