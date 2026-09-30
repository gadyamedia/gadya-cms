@php
    /*
     * One question of a built form. Every input has a label; a question
     * with several inputs is a fieldset with a legend; help and errors are
     * tied to the input with aria-describedby, and an input with an error
     * says so with aria-invalid.
     */
    $key = $field['key'];
    $kind = $field['type'];
    $required = (bool) $field['required'];
    $conditional = $field['logic'] !== null;
    $helpId = $fieldId.'-help';
    $errorId = $fieldId.'-error';
    $describedBy = trim(($field['help'] !== '' ? $helpId : '').' '.$errorId);
    $aria = new \Illuminate\Support\HtmlString(' aria-describedby="'.e($describedBy).'"'.($error ? ' aria-invalid="true"' : '').($required && ! $conditional ? ' required' : '').($required ? ' aria-required="true"' : ''));
    $rules = (array) $field['rules'];
    $placeholder = $field['placeholder'] !== '' ? new \Illuminate\Support\HtmlString(' placeholder="'.e($field['placeholder']).'"') : '';
    $scalar = is_scalar($value) ? (string) $value : '';
    $list = array_map('strval', array_filter((array) $value, 'is_scalar'));
    $part = fn (string $name): string => is_array($value) && is_scalar($value[$name] ?? null) ? (string) $value[$name] : '';
    $star = new \Illuminate\Support\HtmlString($required ? ' <span class="cms-bform__required" aria-hidden="true">*</span>' : '');
    $isGroup = in_array($kind, ['radio', 'checkboxes', 'yes_no', 'rating', 'scale', 'name', 'address'], true);
    $classes = 'cms-bform__field cms-bform__field--'.$kind.' cms-bform__field--'.$field['width'].($error ? ' cms-bform__field--invalid' : '');
    $textTypes = ['short_text' => 'text', 'email' => 'email', 'phone' => 'tel', 'number' => 'number', 'url' => 'url', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local'];
    $autocomplete = ['email' => 'email', 'phone' => 'tel', 'url' => 'url'];
    $inputmode = ['email' => 'email', 'phone' => 'tel', 'number' => 'decimal', 'url' => 'url'];
@endphp

@if ($kind === 'hidden')
    <input type="hidden" name="{{ $key }}" value="{{ $scalar }}" data-cms-field="{{ $key }}">
@elseif ($type !== null && ! $type->isInput())
    <div class="{{ $classes }}" data-cms-field="{{ $key }}" @if ($conditional) data-logic="{{ json_encode($field['logic']) }}" @endif>
        @switch($kind)
            @case('heading')
                <h3 class="cms-bform__heading">{{ $field['label'] }}</h3>
                @if ($field['help'] !== '')
                    <p class="cms-bform__help">{{ $field['help'] }}</p>
                @endif
                @break
            @case('paragraph')
                <div class="cms-bform__paragraph">@cmsMarkdown($field['text'] ?? ($field['help'] !== '' ? $field['help'] : $field['label']))</div>
                @break
            @case('divider')
                <hr class="cms-bform__divider">
                @break
        @endswitch
    </div>
@else
    <div class="{{ $classes }}" data-cms-field="{{ $key }}" data-type="{{ $kind }}" @if ($required) data-required @endif @if ($conditional) data-logic="{{ json_encode($field['logic']) }}" @endif>
        @if ($isGroup)
            <fieldset class="cms-bform__group" @if ($kind === 'radio' || $kind === 'yes_no' || $kind === 'rating' || $kind === 'scale') role="radiogroup" @endif aria-describedby="{{ $describedBy }}" @if ($required) aria-required="true" @endif>
                <legend class="cms-bform__label" id="{{ $fieldId }}">{{ $field['label'] }}{{ $star }}</legend>
                @if ($field['help'] !== '')
                    <p class="cms-bform__help" id="{{ $helpId }}">{{ $field['help'] }}</p>
                @endif

                @switch($kind)
                    @case('radio')
                        <div class="cms-bform__choices">
                            @foreach ($field['options'] as $index => $option)
                                <div class="cms-bform__choice">
                                    <input type="radio" id="{{ $fieldId }}-{{ $index }}" name="{{ $key }}" value="{{ $option['key'] }}" @checked($scalar === $option['key']) @if ($required && ! $conditional && $index === 0) required @endif @if ($error) aria-invalid="true" @endif>
                                    <label for="{{ $fieldId }}-{{ $index }}">{{ $option['label'] }}</label>
                                </div>
                            @endforeach
                        </div>
                        @break
                    @case('checkboxes')
                        <div class="cms-bform__choices">
                            @foreach ($field['options'] as $index => $option)
                                <div class="cms-bform__choice">
                                    <input type="checkbox" id="{{ $fieldId }}-{{ $index }}" name="{{ $key }}[]" value="{{ $option['key'] }}" @checked(in_array($option['key'], $list, true)) @if ($error) aria-invalid="true" @endif>
                                    <label for="{{ $fieldId }}-{{ $index }}">{{ $option['label'] }}</label>
                                </div>
                            @endforeach
                        </div>
                        @break
                    @case('yes_no')
                        <div class="cms-bform__choices cms-bform__choices--inline">
                            @foreach (['yes' => 'Yes', 'no' => 'No'] as $choice => $word)
                                <div class="cms-bform__choice">
                                    <input type="radio" id="{{ $fieldId }}-{{ $choice }}" name="{{ $key }}" value="{{ $choice }}" @checked($scalar === $choice) @if ($required && ! $conditional && $choice === 'yes') required @endif @if ($error) aria-invalid="true" @endif>
                                    <label for="{{ $fieldId }}-{{ $choice }}">{{ __($word) }}</label>
                                </div>
                            @endforeach
                        </div>
                        @break
                    @case('rating')
                        @php $stars = max(3, min(10, (int) ($rules['stars'] ?? 5))); @endphp
                        <div class="cms-bform__stars">
                            @for ($count = 1; $count <= $stars; $count++)
                                <input class="cms-bform__star-input" type="radio" id="{{ $fieldId }}-{{ $count }}" name="{{ $key }}" value="{{ $count }}" @checked($scalar === (string) $count) @if ($required && ! $conditional && $count === 1) required @endif @if ($error) aria-invalid="true" @endif>
                                <label class="cms-bform__star" for="{{ $fieldId }}-{{ $count }}"><span aria-hidden="true">★</span><span class="cms-bform__sr">{{ $count }} {{ $count === 1 ? 'star' : 'stars' }}</span></label>
                            @endfor
                        </div>
                        @break
                    @case('scale')
                        @php
                            $from = is_numeric($rules['min'] ?? null) ? (int) $rules['min'] : 0;
                            $to = is_numeric($rules['max'] ?? null) ? (int) $rules['max'] : 10;
                        @endphp
                        <div class="cms-bform__scale">
                            @for ($point = $from; $point <= $to; $point++)
                                <div class="cms-bform__scale-point">
                                    <input type="radio" id="{{ $fieldId }}-{{ $point }}" name="{{ $key }}" value="{{ $point }}" @checked($scalar === (string) $point) @if ($required && ! $conditional && $point === $from) required @endif @if ($error) aria-invalid="true" @endif>
                                    <label for="{{ $fieldId }}-{{ $point }}">{{ $point }}</label>
                                </div>
                            @endfor
                        </div>
                        @if (filled($rules['low_label'] ?? null) || filled($rules['high_label'] ?? null))
                            <p class="cms-bform__scale-ends"><span>{{ $from }}: {{ $rules['low_label'] ?? '' }}</span> <span>{{ $to }}: {{ $rules['high_label'] ?? '' }}</span></p>
                        @endif
                        @break
                    @case('name')
                        <div class="cms-bform__parts">
                            <div class="cms-bform__part">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-first">First name</label>
                                <input class="cms-bform__input" type="text" id="{{ $fieldId }}-first" name="{{ $key }}[first]" value="{{ $part('first') }}" autocomplete="given-name" maxlength="80" @if ($required && ! $conditional) required @endif @if ($error) aria-invalid="true" @endif>
                            </div>
                            <div class="cms-bform__part">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-last">Last name</label>
                                <input class="cms-bform__input" type="text" id="{{ $fieldId }}-last" name="{{ $key }}[last]" value="{{ $part('last') }}" autocomplete="family-name" maxlength="80" @if ($required && ! $conditional) required @endif @if ($error) aria-invalid="true" @endif>
                            </div>
                        </div>
                        @break
                    @case('address')
                        <div class="cms-bform__parts cms-bform__parts--address">
                            <div class="cms-bform__part cms-bform__part--full">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-line1">Street address</label>
                                <input class="cms-bform__input" type="text" id="{{ $fieldId }}-line1" name="{{ $key }}[line1]" value="{{ $part('line1') }}" autocomplete="address-line1" maxlength="160" @if ($required && ! $conditional) required @endif>
                            </div>
                            <div class="cms-bform__part cms-bform__part--full">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-line2">Apartment, suite or unit <span class="cms-bform__optional">(optional)</span></label>
                                <input class="cms-bform__input" type="text" id="{{ $fieldId }}-line2" name="{{ $key }}[line2]" value="{{ $part('line2') }}" autocomplete="address-line2" maxlength="160">
                            </div>
                            <div class="cms-bform__part">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-city">City or town</label>
                                <input class="cms-bform__input" type="text" id="{{ $fieldId }}-city" name="{{ $key }}[city]" value="{{ $part('city') }}" autocomplete="address-level2" maxlength="80" @if ($required && ! $conditional) required @endif>
                            </div>
                            <div class="cms-bform__part">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-state">State</label>
                                <select class="cms-bform__select" id="{{ $fieldId }}-state" name="{{ $key }}[state]" autocomplete="address-level1" @if ($required && ! $conditional) required @endif>
                                    <option value="">Choose a state</option>
                                    @foreach (\Gadya\Cms\Forms\Builder\Places::usStates() as $code => $stateName)
                                        <option value="{{ $code }}" @selected(($part('state') ?: ($rules['default_state'] ?? 'NJ')) === $code)>{{ $stateName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="cms-bform__part">
                                <label class="cms-bform__sublabel" for="{{ $fieldId }}-zip">ZIP code</label>
                                <input class="cms-bform__input" type="text" id="{{ $fieldId }}-zip" name="{{ $key }}[zip]" value="{{ $part('zip') }}" autocomplete="postal-code" inputmode="numeric" maxlength="10" pattern="\d{5}(-\d{4})?" @if ($required && ! $conditional) required @endif>
                            </div>
                        </div>
                        @break
                @endswitch
            </fieldset>
        @elseif (in_array($kind, ['checkbox', 'consent', 'mailing_list'], true))
            <div class="cms-bform__choice cms-bform__choice--single">
                <input type="checkbox" id="{{ $fieldId }}" name="{{ $key }}" value="1" @checked(filter_var($scalar, FILTER_VALIDATE_BOOLEAN)) {{ $aria }}>
                <label for="{{ $fieldId }}">{{ $kind === 'consent' && filled($field['text'] ?? null) ? $field['text'] : $field['label'] }}{{ $star }}</label>
            </div>
            @if ($field['help'] !== '')
                <p class="cms-bform__help" id="{{ $helpId }}">{{ $field['help'] }}</p>
            @endif
        @else
            <label class="cms-bform__label" for="{{ $fieldId }}" id="{{ $fieldId }}-label">{{ $field['label'] }}{{ $star }}</label>
            @if ($field['help'] !== '')
                <p class="cms-bform__help" id="{{ $helpId }}">{{ $field['help'] }}</p>
            @endif

            @switch($kind)
                @case('long_text')
                    <textarea class="cms-bform__textarea" id="{{ $fieldId }}" name="{{ $key }}" rows="{{ (int) ($rules['rows'] ?? 5) }}" maxlength="{{ (int) ($rules['max_length'] ?? 5000) }}" @if (is_numeric($rules['min_length'] ?? null)) minlength="{{ (int) $rules['min_length'] }}" @endif{{ $placeholder }}{{ $aria }}>{{ $scalar }}</textarea>
                    @break
                @case('currency')
                    <div class="cms-bform__affix">
                        <span class="cms-bform__prefix" aria-hidden="true">{{ config('gadya-cms.menus.currency_symbol', '$') }}</span>
                        <input class="cms-bform__input" type="number" id="{{ $fieldId }}" name="{{ $key }}" value="{{ $scalar }}" step="0.01" inputmode="decimal" min="{{ is_numeric($rules['min'] ?? null) ? $rules['min'] : 0 }}" @if (is_numeric($rules['max'] ?? null)) max="{{ $rules['max'] }}" @endif{{ $placeholder }}{{ $aria }}>
                    </div>
                    @break
                @case('select')
                @case('multi_select')
                @case('country')
                @case('us_state')
                    @php
                        $choices = match ($kind) {
                            'country' => \Gadya\Cms\Forms\Builder\Places::countries(),
                            'us_state' => \Gadya\Cms\Forms\Builder\Places::usStates(),
                            default => collect($field['options'])->pluck('label', 'key')->all(),
                        };
                        $multiple = $kind === 'multi_select';
                    @endphp
                    <select class="cms-bform__select" id="{{ $fieldId }}" name="{{ $key }}{{ $multiple ? '[]' : '' }}" @if ($multiple) multiple size="{{ min(8, max(3, count($choices))) }}" @endif @if ($kind === 'country') autocomplete="country" @endif{{ $aria }}>
                        @unless ($multiple)
                            <option value="">{{ $field['placeholder'] !== '' ? $field['placeholder'] : 'Choose one' }}</option>
                        @endunless
                        @foreach ($choices as $choiceKey => $choiceLabel)
                            <option value="{{ $choiceKey }}" @selected($multiple ? in_array((string) $choiceKey, $list, true) : $scalar === (string) $choiceKey)>{{ $choiceLabel }}</option>
                        @endforeach
                    </select>
                    @break
                @case('slider')
                    @php
                        $min = is_numeric($rules['min'] ?? null) ? $rules['min'] : 0;
                        $max = is_numeric($rules['max'] ?? null) ? $rules['max'] : 100;
                    @endphp
                    <div class="cms-bform__slider">
                        <input class="cms-bform__range" type="range" id="{{ $fieldId }}" name="{{ $key }}" min="{{ $min }}" max="{{ $max }}" step="{{ is_numeric($rules['step'] ?? null) ? $rules['step'] : 1 }}" value="{{ $scalar !== '' ? $scalar : $min }}"{{ $aria }} data-cms-range>
                        <output class="cms-bform__output" for="{{ $fieldId }}" data-cms-output>{{ $scalar !== '' ? $scalar : $min }}</output>
                    </div>
                    @break
                @case('file')
                @case('image')
                    @php
                        $accept = collect((array) ($rules['accept'] ?? []))->map(fn ($ext) => '.'.ltrim(strtolower((string) $ext), '.'))->implode(',');
                    @endphp
                    <input class="cms-bform__file" type="file" id="{{ $fieldId }}" name="{{ $key }}[]" @if ($rules['multiple'] ?? false) multiple @endif accept="{{ $accept !== '' ? $accept : ($kind === 'image' ? 'image/*' : '') }}"{{ $aria }}>
                    @break
                @case('signature')
                    <div class="cms-bform__signature" data-cms-signature>
                        <canvas class="cms-bform__canvas" width="600" height="180" role="img" aria-label="Draw your signature here" hidden data-cms-signature-pad></canvas>
                        <input type="hidden" name="{{ $key }}" value="" data-cms-signature-value>
                        <button type="button" class="cms-bform__clear" hidden data-cms-signature-clear>Clear</button>
                        <div data-cms-signature-typed>
                            <input class="cms-bform__input" type="text" id="{{ $fieldId }}" name="{{ $key }}" value="{{ str_starts_with($scalar, 'data:') ? '' : $scalar }}" placeholder="Type your full name to sign" autocomplete="name"{{ $aria }}>
                        </div>
                    </div>
                    @break
                @default
                    @if ($type !== null && $type->getView() !== null)
                        @include($type->getView(), ['field' => $field, 'id' => $fieldId, 'name' => $key, 'value' => $value, 'describedBy' => $describedBy, 'invalid' => (bool) $error, 'form' => $form])
                    @else
                        <input class="cms-bform__input" type="{{ $textTypes[$kind] ?? 'text' }}" id="{{ $fieldId }}" name="{{ $key }}" value="{{ $scalar }}"
                            @if (isset($autocomplete[$kind])) autocomplete="{{ $autocomplete[$kind] }}" @endif
                            @if (isset($inputmode[$kind])) inputmode="{{ $inputmode[$kind] }}" @endif
                            @if ($kind === 'short_text') maxlength="{{ (int) ($rules['max_length'] ?? 255) }}" @if (is_numeric($rules['min_length'] ?? null)) minlength="{{ (int) $rules['min_length'] }}" @endif @endif
                            @if ($kind === 'number' && is_numeric($rules['min'] ?? null)) min="{{ $rules['min'] }}" @endif
                            @if ($kind === 'number' && is_numeric($rules['max'] ?? null)) max="{{ $rules['max'] }}" @endif
                            @if ($kind === 'number') step="any" @endif
                            @if (in_array($kind, ['date', 'datetime'], true) && ($rules['future'] ?? false)) min="{{ $kind === 'date' ? app(\Gadya\Cms\Support\SiteTimezone::class)->now()->toDateString() : app(\Gadya\Cms\Support\SiteTimezone::class)->now()->format('Y-m-d\TH:i') }}" @endif
                            {{ $placeholder }}{{ $aria }}>
                    @endif
            @endswitch
        @endif

        <p class="cms-bform__error" id="{{ $errorId }}" data-cms-error @if (! $error) hidden @endif>{{ $error }}</p>
    </div>
@endif
