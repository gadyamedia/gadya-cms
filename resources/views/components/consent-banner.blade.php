{{--
    The privacy banner. Put it once in the layout, just before </body>. It
    renders nothing until it is switched on under Settings → Privacy
    choices. Not a modal: the page stays usable behind it, "Reject all"
    sits beside "Accept all" and weighs the same, and every control is a
    real button a keyboard reaches in order.

    Every word it says is written by the client under Settings → Privacy
    choices; for someone editing, the banner links there.

    Styles are deliberately light and live under :where(), so any rule the
    site writes for .cms-consent wins. The colours follow these custom
    properties: --cms-consent-bg, --cms-consent-ink, --cms-consent-accent,
    --cms-consent-accent-ink.
--}}
@php
    $settings = app(\Gadya\Cms\Privacy\Consent::class)->settings();
    $text = $settings['text'];
    $categories = $settings['categories'];
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
    $editUrl = $editor->isEnabled()
        ? rescue(fn () => \Gadya\Cms\Filament\Pages\PrivacySettings::getUrl(panel: (string) config('gadya-cms.panel', 'admin')), null, report: false)
        : null;
@endphp

@if ($settings['banner_enabled'])
    <style @if ($nonce) nonce="{{ $nonce }}" @endif>
        :where(.cms-consent) { position: fixed; inset: auto 1rem 1rem 1rem; z-index: 2147483000; max-width: 40rem; margin-inline: auto; padding: 1.25rem; border-radius: .75rem; background: var(--cms-consent-bg, #fff); color: var(--cms-consent-ink, #111); box-shadow: 0 10px 40px rgb(0 0 0 / .25); font-size: 1rem; line-height: 1.5; max-height: calc(100vh - 2rem); overflow: auto; }
        :where(.cms-consent[hidden], .cms-consent [hidden]) { display: none !important; }
        :where(.cms-consent__heading) { margin: 0 0 .5rem; font-size: 1.15rem; }
        :where(.cms-consent__heading:focus) { outline: none; }
        :where(.cms-consent__message) { margin: 0 0 1rem; }
        :where(.cms-consent__actions) { display: flex; flex-wrap: wrap; gap: .5rem; }
        :where(.cms-consent__button) { flex: 1 1 8rem; min-height: 2.75rem; padding: .5rem 1rem; border: 2px solid var(--cms-consent-accent, #111); border-radius: 999px; background: var(--cms-consent-accent, #111); color: var(--cms-consent-accent-ink, #fff); font: inherit; font-weight: 700; cursor: pointer; }
        :where(.cms-consent__button--quiet) { background: transparent; color: inherit; }
        :where(.cms-consent__button:focus-visible, .cms-consent input:focus-visible, .cms-consent a:focus-visible) { outline: 3px solid var(--cms-consent-accent, #111); outline-offset: 2px; }
        :where(.cms-consent__choices) { margin-top: 1rem; }
        :where(.cms-consent__choices fieldset) { border: 0; margin: 0 0 1rem; padding: 0; display: grid; gap: .75rem; }
        :where(.cms-consent__choice) { display: grid; grid-template-columns: auto 1fr; gap: .25rem .75rem; align-items: start; }
        :where(.cms-consent__choice input) { width: 1.25rem; height: 1.25rem; margin-top: .15rem; }
        :where(.cms-consent__choice small) { grid-column: 2; opacity: .85; }
        @media (prefers-reduced-motion: no-preference) { :where(.cms-consent) { transition: opacity .2s; } }
    </style>

    <section class="cms-consent" id="privacy-choices" data-cms-consent-banner aria-labelledby="cms-consent-heading" hidden>
        <h2 class="cms-consent__heading" id="cms-consent-heading" tabindex="-1" data-cms-consent-heading>{{ $text['heading'] }}</h2>
        <p class="cms-consent__message">
            {{ $text['message'] }}
            @if ($settings['policy_url'])
                <a class="cms-consent__policy" href="{{ $settings['policy_url'] }}">{{ $text['policy'] }}</a>
            @endif
        </p>

        @include('gadya-cms::editor.admin-link', ['url' => $editUrl, 'label' => 'Change what the banner says in the admin'])

        <div class="cms-consent__actions">
            <button type="button" class="cms-consent__button" data-cms-consent-accept>{{ $text['accept'] }}</button>
            <button type="button" class="cms-consent__button" data-cms-consent-reject>{{ $text['reject'] }}</button>
            <button type="button" class="cms-consent__button cms-consent__button--quiet" data-cms-consent-customise aria-expanded="false" aria-controls="cms-consent-choices">{{ $text['choose'] }}</button>
        </div>

        <form class="cms-consent__choices" id="cms-consent-choices" data-cms-consent-choices hidden>
            <fieldset>
                <legend class="cms-consent__legend">{{ $text['legend'] }}</legend>

                <div class="cms-consent__choice">
                    <input type="checkbox" id="cms-consent-necessary" checked disabled aria-describedby="cms-consent-necessary-help">
                    <label for="cms-consent-necessary">{{ $categories['necessary']['name'] }}</label>
                    <small id="cms-consent-necessary-help">{{ $categories['necessary']['description'] }}</small>
                </div>

                <div class="cms-consent__choice">
                    <input type="checkbox" id="cms-consent-analytics" value="analytics" data-cms-consent-category="analytics" aria-describedby="cms-consent-analytics-help">
                    <label for="cms-consent-analytics">{{ $categories['analytics']['name'] }}</label>
                    <small id="cms-consent-analytics-help">{{ $categories['analytics']['description'] }}</small>
                </div>

                <div class="cms-consent__choice">
                    <input type="checkbox" id="cms-consent-marketing" value="marketing" data-cms-consent-category="marketing" aria-describedby="cms-consent-marketing-help cms-consent-gpc">
                    <label for="cms-consent-marketing">{{ $categories['marketing']['name'] }}</label>
                    <small id="cms-consent-marketing-help">{{ $categories['marketing']['description'] }}</small>
                    <small id="cms-consent-gpc" data-cms-consent-gpc hidden>{{ $text['gpc'] }}</small>
                </div>
            </fieldset>

            <button type="submit" class="cms-consent__button">{{ $text['save'] }}</button>
        </form>
    </section>

    @once('gadya-cms-consent-runtime')
    @include('gadya-cms::privacy.runtime')
    @endonce
@endif
