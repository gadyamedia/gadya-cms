@props([
    /* Leave out to use the wording written under Settings → Privacy choices. */
    'label' => null,
])

{{--
    Reopens the privacy banner from anywhere - the footer, usually. With
    the banner switched off it is a plain link to the privacy policy, or
    nothing at all when there is none.
--}}
@php
    $settings = app(\Gadya\Cms\Privacy\Consent::class)->settings();
    $label ??= $settings['text']['link'];
@endphp

@if ($settings['banner_enabled'])
    <a {{ $attributes->class(['cms-privacy-choices']) }} href="{{ $settings['policy_url'] ?? '#privacy-choices' }}" data-cms-consent-open>{{ $label }}</a>
@elseif ($settings['policy_url'])
    <a {{ $attributes->class(['cms-privacy-choices']) }} href="{{ $settings['policy_url'] }}">{{ $label }}</a>
@endif
