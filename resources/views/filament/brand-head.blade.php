{{--
    The brand's faces and colours, handed to the panel stylesheet as custom
    properties so the CSS itself never hard-codes a palette.
--}}
@if ($fontStylesheet)
    <link rel="preconnect" href="{{ parse_url($fontStylesheet, PHP_URL_SCHEME) }}://{{ parse_url($fontStylesheet, PHP_URL_HOST) }}" crossorigin>
    <link rel="stylesheet" href="{{ $fontStylesheet }}">
@endif

<style>
    :root {
        --gadya-cms-primary: {{ $primary }};
        --gadya-cms-secondary: {{ $secondary }};
        --gadya-cms-background: {{ $background }};
        --gadya-cms-ink: {{ $ink }};
        --gadya-cms-accent: {{ $accent }};
        --gadya-cms-display-font: '{{ $displayFont }}', Georgia, serif;
        --gadya-cms-logo-height: {{ $logoHeight }};
        --gadya-cms-logo-height-auth: {{ $logoHeightAuth }};

        /* Dashboard surfaces, light by default and swapped below for dark. */
        --gadya-dash-surface: #ffffff;
        --gadya-dash-line: #e5e7eb;
        --gadya-dash-ink: #111827;
        --gadya-dash-soft: #4b5563;
        --gadya-dash-muted: #9ca3af;
        --gadya-dash-chip: #f3f4f6;
    }

    .dark {
        --gadya-dash-surface: transparent;
        --gadya-dash-line: #374151;
        --gadya-dash-ink: #f9fafb;
        --gadya-dash-soft: #d1d5db;
        --gadya-dash-muted: #9ca3af;
        --gadya-dash-chip: #1f2937;
    }
</style>
