@props([
    'form' => null,
    'button' => null,
    'title' => null,
    'intro' => null,
])

@php
    /*
     * <x-gadya-cms::form-popup form="quote" button="Get a free quote" />
     *
     * A button that opens a built form in a native <dialog>: the page
     * behind is inert, Escape closes it and focus goes back to the button.
     * Without JavaScript the form simply shows in the page. It opens again
     * by itself when the form comes back with an error or a thank-you.
     */
    $model = $form instanceof \Gadya\Cms\Models\Form ? $form : \Gadya\Cms\Models\Form::findLive((string) $form);
    $popupId = 'cms-popup-'.($model?->slug ?? 'form').'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(5));
    $bag = session('errors') instanceof \Illuminate\Support\ViewErrorBag ? session('errors')->getBag('gadya-cms.'.($model?->slug ?? '')) : null;
    $reopen = $model !== null && (($bag?->any() ?? false) || session()->has('gadya-cms.form.'.$model->slug) || session()->has('gadya-cms.form-saved.'.$model->slug));
@endphp

@if ($model)
    <div {{ $attributes->merge(['class' => 'cms-bform-popup']) }} data-cms-bform-popup>
        <button type="button" class="cms-bform__popup-open" aria-haspopup="dialog" aria-controls="{{ $popupId }}" data-cms-popup-open>{{ $button ?? $model->title }}</button>
        <dialog id="{{ $popupId }}" class="cms-bform__popup-dialog" aria-label="{{ $title ?? $model->title }}" @if ($reopen) data-cms-popup-reopen @endif>
            <button type="button" class="cms-bform__popup-close" aria-label="Close" data-cms-popup-close>&times;</button>
            {{ app(\Gadya\Cms\Forms\Builder\FormRenderer::class)->render($model, ['title' => $title ?? $model->title, 'intro' => $intro]) }}
        </dialog>
    </div>

    @once
        <noscript><style>.cms-bform__popup-open,.cms-bform__popup-close{display:none}.cms-bform__popup-dialog:not([open]){display:block;position:static}</style></noscript>
        <script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
            (() => {
                const wire = (root) => {
                    if (root.dataset.cmsPopupReady) {
                        return;
                    }

                    root.dataset.cmsPopupReady = '1';

                    const opener = root.querySelector('[data-cms-popup-open]');
                    const dialog = root.querySelector('dialog');

                    if (! opener || ! dialog || typeof dialog.showModal !== 'function') {
                        return;
                    }

                    const open = () => {
                        dialog.showModal();
                        (dialog.querySelector('[aria-invalid="true"], [data-cms-success]:not([hidden])') || dialog.querySelector('input:not([type="hidden"]):not([tabindex="-1"]), select, textarea'))?.focus();
                    };

                    opener.addEventListener('click', open);
                    root.querySelector('[data-cms-popup-close]')?.addEventListener('click', () => dialog.close());
                    dialog.addEventListener('close', () => opener.focus());
                    dialog.addEventListener('click', (event) => {
                        if (event.target === dialog) {
                            dialog.close();
                        }
                    });

                    if (dialog.hasAttribute('data-cms-popup-reopen')) {
                        open();
                    }
                };

                const start = () => document.querySelectorAll('[data-cms-bform-popup]').forEach(wire);

                document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
            })();
        </script>
    @endonce
@endif
