@php
    /*
     * The error bag is shared with views by the session middleware, but a
     * directive rendered outside a request - a test, a queued mail - has no
     * such thing, so fall back to an empty bag rather than failing.
     */
    $bag = (isset($errors) ? $errors : (session('errors') ?? new \Illuminate\Support\ViewErrorBag))->getBag('gadya-cms.'.$form);
@endphp

@if (session()->has('gadya-cms.form.'.$form))
    <p class="cms-form__success" role="status">{{ session('gadya-cms.form.'.$form) }}</p>
@endif

@if ($bag->any())
    <ul class="cms-form__errors" role="alert">
        @foreach ($bag->all() as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
