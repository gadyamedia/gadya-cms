{{--
    Shown only to someone with the live editor on who may build forms: the
    way to change this form, and - in a page section - to put another one
    in its place. The swap is saved to the draft like any other edit.
--}}
<div class="gadya-cms-form-tools" data-cms-form-tools @if ($section) data-cms-section="{{ $section }}" @endif>
    <p class="gadya-cms-form-tools__label">{{ $form ? 'Form: '.$form->title : 'No form here yet' }}</p>
    @if ($editUrl)
        <a class="gadya-cms-link" href="{{ $editUrl }}">Edit this form</a>
    @elseif (! $form && $panelUrl)
        <a class="gadya-cms-link" href="{{ rtrim($panelUrl, '/') }}/forms">Build a form</a>
    @endif
    @if ($section && $choices !== [])
        <label class="gadya-cms-form-tools__choose">
            <span>{{ $form ? 'Choose another form' : 'Choose a form' }}</span>
            <select class="gadya-cms-input" data-cms-form-choice>
                @foreach ($choices as $slug => $label)
                    <option value="{{ $slug }}" @selected($form?->slug === $slug)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" class="gadya-cms-button" data-cms-form-choose>Use this form</button>
    @endif
</div>
