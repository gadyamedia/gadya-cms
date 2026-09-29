{{--
    A trimmed, anonymised copy of a real site's contact page (the first of
    two brands, in en/ru/uk): every word is a lang key, the choices are
    drawn in @foreach loops over what the page's controller passes, the
    labels wrap their controls beside @error blocks, and the send button
    carries the site's analytics event.
--}}
@extends('layouts.app', [
    'meta_title' => __('pages.contact.meta_title'),
    'canonical' => locale_route('contact'),
])

@section('content')
    <section class="pt-32 pb-16">
        <div class="section-number mb-6">{{ __('pages.contact.eyebrow') }}</div>
        <h1 class="font-display">{{ __('pages.contact.heading_lead') }}</h1>
    </section>

    @if (session('status'))
        <div class="bg-ember text-bone px-6 py-4 rounded-sm">{{ session('status') }}</div>
    @endif

    <section class="pb-20 lg:pb-28">
        <div class="grid lg:grid-cols-12 gap-10">
            {{-- Contact Form --}}
            <div class="lg:col-span-7 bg-paper p-8 lg:p-12 border border-ink/10">
                <h2 class="font-display text-3xl lg:text-4xl leading-tight mb-2">{{ __('pages.contact.form_heading') }}</h2>
                <p class="text-ash mb-8">{{ __('pages.contact.form_note') }}</p>

                <form action="{{ locale_route('contact.submit') }}" method="POST" class="space-y-6">
                    @csrf
                    <label class="absolute -left-[9999px]" aria-hidden="true">
                        {{ __('pages.contact.honeypot') }}
                        <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
                    </label>
                    <div class="grid md:grid-cols-2 gap-6">
                        <label class="block">
                            <span class="caps text-[0.66rem] text-stone block mb-2">{{ __('pages.contact.name') }}</span>
                            <input type="text" name="name" required value="{{ old('name') }}" class="w-full border-0 border-b border-ink/30 bg-transparent py-2 text-lg">
                            @error('name') <span class="text-ember text-xs mt-1 block">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="caps text-[0.66rem] text-stone block mb-2">{{ __('pages.contact.email') }}</span>
                            <input type="email" name="email" required value="{{ old('email') }}" class="w-full border-0 border-b border-ink/30 bg-transparent py-2 text-lg">
                            @error('email') <span class="text-ember text-xs mt-1 block">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <label class="block">
                        <span class="caps text-[0.66rem] text-stone block mb-2">{{ __('pages.contact.interest') }}</span>
                        <select name="service_interest" class="w-full border-0 border-b border-ink/30 bg-transparent py-2 text-lg">
                            <option value="">{{ __('pages.contact.interest_none') }}</option>
                            @foreach ($services as $service)
                                <option value="{{ $service['slug'] }}" @selected(old('service_interest') === $service['slug'])>{{ $service['name'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="grid md:grid-cols-2 gap-6">
                        <label class="block">
                            <span class="caps text-[0.66rem] text-stone block mb-2">{{ __('pages.contact.phone') }}</span>
                            <input type="tel" name="phone" value="{{ old('phone') }}" class="w-full border-0 border-b border-ink/30 bg-transparent py-2 text-lg">
                        </label>
                        <label class="block">
                            <span class="caps text-[0.66rem] text-stone block mb-2">{{ __('pages.contact.preferred_location') }}</span>
                            <select name="location" class="w-full border-0 border-b border-ink/30 bg-transparent py-2 text-lg">
                                @foreach ($locations as $loc)
                                    <option value="{{ $loc['slug'] }}" @selected(old('location') === $loc['slug'])>{{ $loc['locality'] }}, {{ $loc['state'] }}</option>
                                @endforeach
                                <option value="either" @selected(old('location') === 'either')>{{ __('pages.contact.location_either') }}</option>
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="caps text-[0.66rem] text-stone block mb-2">{{ __('pages.contact.message') }}</span>
                        <textarea name="message" required rows="5" class="w-full border-0 border-b border-ink/30 bg-transparent py-2 text-base leading-relaxed resize-none">{{ old('message') }}</textarea>
                        @error('message') <span class="text-ember text-xs mt-1 block">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex items-start gap-3 text-sm leading-relaxed text-ash">
                        <input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent')) class="mt-1 h-4 w-4 accent-ember">
                        <span>{{ __('pages.contact.consent_before') }} <a href="{{ locale_route('privacy') }}" class="underline hover:text-ember">{{ __('pages.contact.consent_link') }}</a> {{ __('pages.contact.consent_after') }}</span>
                    </label>
                    @error('privacy_consent') <span class="text-ember text-xs block">{{ $message }}</span> @enderror

                    <button type="submit" class="btn-primary" data-analytics="cta_click" data-analytics-label="Contact form submit">
                        {{ __('pages.contact.submit') }}
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 5l7 7-7 7" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            </div>

            {{-- Location cards --}}
            <div class="lg:col-span-5 flex flex-col gap-5">
                @foreach ($locations as $loc)
                    <div class="bg-ink text-bone p-8 flex flex-col">
                        <div class="font-display text-2xl lg:text-3xl leading-tight mb-3">{{ $loc['name'] }}</div>
                        <div class="text-bone/75 mb-5 leading-relaxed">{{ $loc['street'] }}<br>{{ $loc['locality'] }}, {{ $loc['state'] }} {{ $loc['postal'] }}</div>
                        <a href="tel:{{ $loc['phone_e164'] }}" class="inline-flex items-center gap-2 bg-ember px-5 py-3 rounded-full">{{ $loc['phone'] }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
