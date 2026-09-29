{{--
    A trimmed, anonymised copy of the second brand's contact page on the
    same site: every word written out in English, required labels marked
    with "*", the location choices written out, and the consent sentence
    with a link to the privacy policy inside it.
--}}
@extends('avant.layouts.app', [
    'meta_title' => 'Appointments — Avant Clinic',
])

@section('content')
    <section class="pt-32 pb-12 lg:pt-40 lg:pb-16 av-soft-grad">
        <div class="container-x">
            <div class="av-eyebrow mb-5">— Make an appointment</div>
            <h1 class="av-display">Same-day or 24-hour appointments.</h1>
        </div>
    </section>

    @if (session('status'))
        <div class="container-x mb-6">
            <div class="bg-royal text-white px-6 py-4 rounded-xl">{{ session('status') }}</div>
        </div>
    @endif

    <section class="pb-20 lg:pb-28">
        <div class="container-x grid lg:grid-cols-12 gap-8">
            <div class="lg:col-span-7 av-card p-8 lg:p-12">
                <h2 class="font-display text-3xl lg:text-4xl font-semibold mb-2">Request an appointment</h2>
                <p class="text-ash mb-8">Confirmed within one business day.</p>

                <form action="{{ route('avant.contact.submit') }}" method="POST" class="space-y-6">
                    @csrf
                    <label class="absolute -left-[9999px]" aria-hidden="true">
                        Website
                        <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
                    </label>
                    <div class="grid md:grid-cols-2 gap-6">
                        <label class="block">
                            <span class="text-[0.74rem] uppercase tracking-[0.12em] text-slate font-semibold block mb-2">Name *</span>
                            <input type="text" name="name" required value="{{ old('name') }}"
                                class="w-full border border-line rounded-lg px-4 py-3 text-base bg-white">
                            @error('name') <span class="text-crimson text-xs mt-1 block">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-[0.74rem] uppercase tracking-[0.12em] text-slate font-semibold block mb-2">Email *</span>
                            <input type="email" name="email" required value="{{ old('email') }}"
                                class="w-full border border-line rounded-lg px-4 py-3 text-base bg-white">
                            @error('email') <span class="text-crimson text-xs mt-1 block">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <label class="block">
                            <span class="text-[0.74rem] uppercase tracking-[0.12em] text-slate font-semibold block mb-2">Phone</span>
                            <input type="tel" name="phone" value="{{ old('phone') }}"
                                class="w-full border border-line rounded-lg px-4 py-3 text-base bg-white">
                        </label>
                        <label class="block">
                            <span class="text-[0.74rem] uppercase tracking-[0.12em] text-slate font-semibold block mb-2">Preferred location</span>
                            <select name="location" class="w-full border border-line rounded-lg px-4 py-3 text-base bg-white">
                                <option value="northtown-nj">Northtown, NJ</option>
                                <option value="southside-ny">Southside, NY</option>
                                <option value="either">Either</option>
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-[0.74rem] uppercase tracking-[0.12em] text-slate font-semibold block mb-2">What would you like help with? *</span>
                        <textarea name="message" required rows="5"
                            class="w-full border border-line rounded-lg px-4 py-3 text-base bg-white resize-none">{{ old('message') }}</textarea>
                        @error('message') <span class="text-crimson text-xs mt-1 block">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex items-start gap-3 text-sm leading-relaxed text-ash">
                        <input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent')) class="mt-1 h-4 w-4 accent-royal">
                        <span>I agree to the <a href="{{ route('privacy') }}" class="underline hover:text-royal">privacy policy</a> and consent to being contacted about this request. I understand this form is not for emergencies or sensitive medical information.</span>
                    </label>
                    @error('privacy_consent') <span class="text-crimson text-xs block">{{ $message }}</span> @enderror

                    <button type="submit" class="av-btn">
                        Send request
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            </div>

            <div class="lg:col-span-5 flex flex-col gap-4">
                @foreach (\App\Data\AvantContent::locations() as $loc)
                    <div class="av-card p-7">
                        <div class="font-display text-2xl font-semibold leading-tight mb-3">{{ $loc['name'] }}</div>
                        <a href="tel:{{ $loc['phone_e164'] }}" class="av-btn text-sm py-2.5 px-4">{{ $loc['phone'] }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
