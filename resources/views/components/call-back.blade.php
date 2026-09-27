@props([
    'button' => null,
    'heading' => null,
    'intro' => null,
])

@php
    /*
     * "Speak with our team": a button that opens a small form asking for
     * a name and a number, posted through the site's forms like any other
     * enquiry and passed to the Gadya portal as a call-back request.
     *
     * The modal is a native <dialog> opened with showModal(), which makes
     * the rest of the page inert and closes on Escape; the script below
     * adds the last few things a screen-reader or keyboard user needs:
     * Tab stays inside, focus starts on the first field and goes back to
     * the button on close. Without JavaScript the form simply shows in
     * the page.
     */
    $form = \Gadya\Cms\Forms\CallbackForm::NAME;
    $id = 'cms-callback-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
    $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');
    $bag = (isset($errors) ? $errors : (session('errors') ?? new \Illuminate\Support\ViewErrorBag))->getBag('gadya-cms.'.$form);
    $success = session('gadya-cms.form.'.$form);
    $button ??= (string) config('gadya-cms.portal.callback.button', 'Speak with our team');
    $heading ??= (string) config('gadya-cms.portal.callback.heading', 'We will call you back');
    $intro ??= (string) config('gadya-cms.portal.callback.intro', 'Leave your number and we will ring you as soon as we can.');
    $field = fn (string $name): string => $id.'-'.$name;
    $invalid = fn (string $name): bool => $bag->has($name);
@endphp

<div {{ $attributes->merge(['class' => 'cms-callback']) }} data-cms-callback>
    <button type="button" class="cms-callback__open" aria-haspopup="dialog" aria-controls="{{ $id }}" data-cms-callback-open>{{ $button }}</button>

    @if ($success)
        <p class="cms-callback__success" role="status">{{ $success }}</p>
    @endif

    <dialog id="{{ $id }}" class="cms-callback__dialog" aria-labelledby="{{ $field('heading') }}" aria-describedby="{{ $field('intro') }}" @if ($bag->any()) data-cms-callback-reopen @endif>
        <form method="POST" action="{{ route('gadya-cms.forms.store', $form) }}" class="cms-callback__form" novalidate>
            @csrf
            <input type="hidden" name="_path" value="{{ request()->path() === '/' ? '/' : '/'.request()->path() }}">
            @if ($honeypot !== '')
                <div class="cms-form__trap" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                    <label for="{{ $field($honeypot) }}">Leave this empty</label>
                    <input type="text" id="{{ $field($honeypot) }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
                </div>
            @endif

            <h2 id="{{ $field('heading') }}" class="cms-callback__heading">{{ $heading }}</h2>
            <p id="{{ $field('intro') }}" class="cms-callback__intro">{{ $intro }}</p>

            @if ($bag->any())
                <ul class="cms-callback__errors" role="alert">
                    @foreach ($bag->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="cms-callback__field">
                <label for="{{ $field('name') }}">Your name</label>
                <input id="{{ $field('name') }}" name="name" type="text" autocomplete="name" required maxlength="120" value="{{ old('name') }}" @if ($invalid('name')) aria-invalid="true" @endif data-cms-callback-first>
            </p>

            <p class="cms-callback__field">
                <label for="{{ $field('phone') }}">Phone number</label>
                <input id="{{ $field('phone') }}" name="phone" type="tel" autocomplete="tel" inputmode="tel" required maxlength="40" value="{{ old('phone') }}" @if ($invalid('phone')) aria-invalid="true" @endif>
            </p>

            <p class="cms-callback__field">
                <label for="{{ $field('message') }}">What is it about? <span class="cms-callback__optional">(optional)</span></label>
                <textarea id="{{ $field('message') }}" name="message" rows="3" maxlength="2000">{{ old('message') }}</textarea>
            </p>

            <p class="cms-callback__consent">
                <input id="{{ $field('consent') }}" name="consent" type="checkbox" value="1" required @if ($invalid('consent')) aria-invalid="true" @endif>
                <label for="{{ $field('consent') }}">{{ \Gadya\Cms\Forms\CallbackForm::consentText() }}</label>
            </p>

            <p class="cms-callback__actions">
                <button type="submit" class="cms-callback__submit">Call me back</button>
                <button type="button" class="cms-callback__close" data-cms-callback-close>Cancel</button>
            </p>
        </form>
    </dialog>
</div>

@once
    <noscript><style>.cms-callback__open{display:none}.cms-callback__dialog:not([open]){display:block;position:static}</style></noscript>
    {{-- Carries the page's CSP nonce when the application sets one through Vite. --}}
    <script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
        (() => {
            const focusable = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]):not([tabindex="-1"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

            const wire = (root) => {
                if (root.dataset.cmsCallbackReady) {
                    return;
                }

                root.dataset.cmsCallbackReady = '1';

                const opener = root.querySelector('[data-cms-callback-open]');
                const dialog = root.querySelector('dialog');

                if (! opener || ! dialog || typeof dialog.showModal !== 'function') {
                    return;
                }

                const open = () => {
                    dialog.showModal();
                    (dialog.querySelector('[aria-invalid="true"]') || dialog.querySelector('[data-cms-callback-first]'))?.focus();
                };

                opener.addEventListener('click', open);
                root.querySelector('[data-cms-callback-close]')?.addEventListener('click', () => dialog.close());
                dialog.addEventListener('close', () => opener.focus());

                /* A click on the backdrop, outside the form, closes it. */
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });

                /* Tab and Shift+Tab go round the form, never out of it. */
                dialog.addEventListener('keydown', (event) => {
                    if (event.key !== 'Tab') {
                        return;
                    }

                    const items = [...dialog.querySelectorAll(focusable)].filter((item) => item.offsetParent !== null);
                    const first = items[0];
                    const last = items[items.length - 1];

                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                });

                if (dialog.hasAttribute('data-cms-callback-reopen')) {
                    open();
                }
            };

            const start = () => document.querySelectorAll('[data-cms-callback]').forEach(wire);

            document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
        })();
    </script>
@endonce
