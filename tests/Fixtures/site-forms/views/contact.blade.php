<section class="contact">
    <h2>{{ __('contact.heading') }}</h2>
    <form method="POST" action="{{ route('contact.send') }}" class="contact-form">
        @csrf
        <label for="c-name">{{ __('contact.name') }}</label>
        <input id="c-name" name="name" required placeholder="{{ __('contact.name_placeholder') }}">

        <label for="c-email">@lang('contact.email')</label>
        <input id="c-email" type="email" name="email" required>

        <label for="c-topic">{{ __('contact.topic') }}</label>
        <select id="c-topic" name="topic">
            <option value="design">{{ __('contact.topics.design') }}</option>
            <option value="build">{{ __('Building work') }}</option>
        </select>

        <textarea name="message" aria-label="{{ trans('contact.message') }}"></textarea>

        <label><input type="checkbox" name="consent" value="1" required> {{ __('contact.consent') }}</label>

        <button type="submit">{{ __('contact.send') }}</button>
    </form>
</section>
