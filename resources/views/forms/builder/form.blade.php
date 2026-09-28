@php
    /*
     * A built form. Plain, semantic markup with cms-bform__* classes; the
     * default stylesheet and the script come once per page, with the
     * first form drawn.
     */
    $errorFor = fn (string $key): ?string => $bag->first($key) ?: ($bag->first($key.'.*') ?: null);
    $anyRequired = collect($schema->inputs())->contains(fn (array $field): bool => $field['required'] && ! in_array($field['type'], ['hidden', 'checkbox', 'mailing_list'], true));
    $classes = trim('cms-bform cms-bform--'.$form->slug.' '.$extraClass);
    $total = count($steps);
@endphp

<div class="{{ $classes }}" id="{{ $id }}" data-cms-bform
     data-slug="{{ $form->slug }}"
     data-version="{{ $form->version }}"
     data-start-step="{{ $startStep }}"
     @if ($eventsUrl) data-events="{{ $eventsUrl }}" @endif
     @if ($saveUrl) data-save="{{ $saveUrl }}" @endif
     @if ($form->setting('local_progress', true) && ! $preview) data-local-progress @endif
     @if ($embed) data-embed @endif
     @if ($preview) data-preview @endif
     @if ($form->setting('redirect')) data-redirect="{{ $form->setting('redirect') }}" @endif>

    {!! $editorTools !!}

    @if (filled($title))
        <h2 class="cms-bform__title" id="{{ $id }}-title">{{ $title }}</h2>
    @endif
    @if (filled($intro))
        <div class="cms-bform__intro">@cmsMarkdown($intro)</div>
    @endif

    @if ($success)
        <p class="cms-bform__success" role="status" tabindex="-1" data-cms-success>{{ $success }}</p>
    @else
        <p class="cms-bform__success" role="status" tabindex="-1" data-cms-success hidden></p>

        @if ($saved)
            <p class="cms-bform__saved" role="status">{{ $saved }}</p>
        @endif

        <form class="cms-bform__form" method="POST" action="{{ $action }}" @if ($schema->hasFiles()) enctype="multipart/form-data" @endif
              @if (filled($title)) aria-labelledby="{{ $id }}-title" @else aria-label="{{ $form->title }}" @endif data-cms-bform-form>
            @unless ($embed)
                @csrf
            @endunless
            <input type="hidden" name="_path" value="{{ request()->path() === '/' ? '/' : '/'.request()->path() }}">
            <input type="hidden" name="_t" value="{{ $seal }}">
            <input type="hidden" name="_started" value="{{ old('_started') }}" data-cms-started>
            <input type="hidden" name="_step" value="{{ $startStep }}" data-cms-step-input>
            @if ($resumeToken)
                <input type="hidden" name="_resume" value="{{ $resumeToken }}">
            @endif
            @if ($honeypot !== '')
                {{-- Never shown to a person; a bot fills it in and gives itself away. --}}
                <div class="cms-form__trap" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                    <label for="{{ $id }}-{{ $honeypot }}">Leave this empty</label>
                    <input type="text" id="{{ $id }}-{{ $honeypot }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
                </div>
            @endif

            <div class="cms-bform__summary" role="alert" tabindex="-1" data-cms-summary @if (! $bag->any()) hidden @endif>
                <p class="cms-bform__summary-title">Please check {{ $bag->count() === 1 ? 'this answer' : 'these answers' }}:</p>
                <ul>
                    @foreach ($bag->keys() as $key)
                        @php $fieldKey = explode('.', $key)[0]; @endphp
                        <li><a href="#{{ $id }}-{{ $fieldKey }}">{{ $bag->first($key) }}</a></li>
                    @endforeach
                </ul>
            </div>

            @if ($anyRequired)
                <p class="cms-bform__note">Questions marked <span class="cms-bform__required" aria-hidden="true">*</span><span class="cms-bform__sr">with a star</span> need an answer.</p>
            @endif

            @if ($multiStep && $form->setting('progress', true))
                <div class="cms-bform__progress" data-cms-progress hidden>
                    <p class="cms-bform__progress-text" aria-live="polite">
                        Step <span data-cms-step-number>{{ $startStep + 1 }}</span> of <span data-cms-step-total>{{ $total }}</span>
                    </p>
                    <div class="cms-bform__bar" aria-hidden="true"><span class="cms-bform__bar-fill" data-cms-bar style="width: {{ round(($startStep + 1) / $total * 100) }}%"></span></div>
                </div>
            @endif

            @foreach ($steps as $step)
                @if ($multiStep)
                    <fieldset class="cms-bform__step" data-cms-step="{{ $step['index'] }}" @if ($step['logic']) data-logic="{{ json_encode($step['logic']) }}" @endif>
                        <legend class="cms-bform__legend" tabindex="-1">{{ $step['title'] !== '' ? $step['title'] : 'Step '.($step['index'] + 1) }}</legend>
                @else
                    <div class="cms-bform__step" data-cms-step="0">
                @endif

                <div class="cms-bform__fields">
                    @foreach ($step['fields'] as $field)
                        @include('gadya-cms::forms.builder.field', [
                            'field' => $field,
                            'type' => $types->get($field['type']),
                            'fieldId' => $id.'-'.$field['key'],
                            'value' => $values[$field['key']] ?? null,
                            'error' => $errorFor($field['key']),
                            'bag' => $bag,
                        ])
                    @endforeach
                </div>

                @if ($multiStep)
                    </fieldset>
                @else
                    </div>
                @endif
            @endforeach

            @if ($turnstile)
                <div class="cms-bform__turnstile cf-turnstile" data-sitekey="{{ $turnstile }}"></div>
                @once
                    <script src="{{ \Gadya\Cms\Forms\Builder\SpamGuard::TURNSTILE_SCRIPT }}" async defer @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif></script>
                @endonce
                @if ($errorFor('cf-turnstile-response'))
                    <p class="cms-bform__error">{{ $errorFor('cf-turnstile-response') }}</p>
                @endif
            @endif

            <div class="cms-bform__actions">
                @if ($multiStep)
                    <button type="button" class="cms-bform__back" data-cms-back hidden>{{ $form->message('back') }}</button>
                    <button type="button" class="cms-bform__next" data-cms-next hidden>{{ $form->message('next') }}</button>
                @endif
                <button type="submit" class="cms-bform__submit" data-cms-submit @if ($preview) disabled @endif>{{ $form->message('submit') }}</button>
            </div>

            @if ($saveUrl && $form->setting('save_later'))
                <details class="cms-bform__later" data-cms-later>
                    <summary>{{ $form->message('save_later') }}</summary>
                    <p class="cms-bform__later-intro">{{ $form->message('save_later_intro') }}</p>
                    <label class="cms-bform__label" for="{{ $id }}-resume-email">Your email address</label>
                    <input class="cms-bform__input" type="email" id="{{ $id }}-resume-email" name="_resume_email" autocomplete="email" inputmode="email">
                    <button type="submit" class="cms-bform__later-send" name="_save" value="1" formnovalidate formaction="{{ $saveUrl }}" data-cms-save>Email me a link</button>
                    <p class="cms-bform__later-status" role="status" data-cms-later-status></p>
                </details>
            @endif

            <p class="cms-bform__restored" role="status" data-cms-restored hidden>
                {{ $form->message('restored') }}
                <button type="button" class="cms-bform__restart" data-cms-restart>{{ $form->message('start_again') }}</button>
            </p>
        </form>
    @endif
</div>

@if ($styles)
    @once
        <style @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>:where(.cms-bform){--cms-form-accent:{{ $theme['accent'] }};--cms-form-ink:{{ $theme['ink'] }}}{!! \Gadya\Cms\Forms\Builder\FormAssets::styles() !!}</style>
    @endonce
@endif

@once
    {{-- Carries the page's CSP nonce when the application sets one through Vite. --}}
    <script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>{!! \Gadya\Cms\Forms\Builder\FormAssets::script() !!}</script>
@endonce
