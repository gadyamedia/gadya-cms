<form class="cms-newsletter" method="POST" action="{{ route('gadya-cms.newsletter.store') }}">
    @csrf
    <input type="hidden" name="_path" value="{{ request()->path() === '/' ? '/' : '/'.request()->path() }}">
    @if ($honeypot !== '')
        <div class="cms-form__trap" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
            <label for="cms-newsletter-{{ $honeypot }}">Leave this empty</label>
            <input type="text" id="cms-newsletter-{{ $honeypot }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
        </div>
    @endif

    <label class="cms-newsletter__label" for="cms-newsletter-email">{{ $label }}</label>
    <div class="cms-newsletter__row">
        <input class="cms-newsletter__input" id="cms-newsletter-email" type="email" name="email" placeholder="you@example.com" required>
        <button class="cms-newsletter__button" type="submit">{{ $button }}</button>
    </div>

    @if (session()->has('gadya-cms.newsletter'))
        <p class="cms-newsletter__message" role="status">{{ session('gadya-cms.newsletter') }}</p>
    @endif

    @php
        /* A directive rendered outside a request - a test, a queued mail - has no error bag. */
        $bag = $errors ?? session('errors') ?? new \Illuminate\Support\ViewErrorBag;
    @endphp

    @if ($bag->has('email'))
        <p class="cms-newsletter__error" role="alert">{{ $bag->first('email') }}</p>
    @endif
</form>
