{{-- Written by gadya-cms:make:page-template, then given a contact form. --}}
@extends('layouts.app')

@section('content')
    @editableFor("pages.{$slug}")

    <article class="page page--{{ $page['type'] ?? 'content' }}">
        <h1 class="page-heading" @editable('heading')>{{ $page['heading'] ?? $page['title'] }}</h1>
        <p class="page-description" @editable('description', 'multiline')>{{ $page['description'] ?? '' }}</p>
        @if (! empty($page['cta']))
            <a class="page-cta" href="{{ url('/contact') }}" data-analytics="cta_click" @editable('cta')>{{ $page['cta'] }}</a>
        @endif
        @if (! empty($page['hero_image']))
            <img class="page-hero_image" src="@siteImage($page['hero_image'], 1200)" srcset="@siteSrcset($page['hero_image'])" sizes="(max-width: 64rem) 100vw, 64rem" alt="" @editable('hero_image', 'image')>
        @endif

        @foreach ($page['sections'] ?? [] as $index => $section)
            <section class="page-section page-section--{{ $section['type'] ?? 'text' }}">
                @if (! empty($section['title']))
                    <h2 @editable("sections.{$index}.title")>{{ $section['title'] }}</h2>
                @endif
                @if (! empty($section['subtitle']))
                    <p class="page-section__subtitle" @editable("sections.{$index}.subtitle")>{{ $section['subtitle'] }}</p>
                @endif

                @if (($section['type'] ?? null) === 'gallery')
                    <div class="page-gallery">
                        @foreach ($section['images'] ?? [] as $imageIndex => $image)
                            <img src="@siteImage($image, 480)" srcset="@siteSrcset($image)" sizes="12rem" alt="" loading="lazy" @editable("sections.{$index}.images.{$imageIndex}", 'image')>
                        @endforeach
                    </div>
                @else
                    <div class="page-cards">
                        @foreach ($section['items'] ?? [] as $itemIndex => $item)
                            <div class="page-card">
                                @if (! empty($item['image']))
                                    <img src="@siteImage($item['image'], 480)" alt="" loading="lazy" @editable("sections.{$index}.items.{$itemIndex}.image", 'image')>
                                @endif
                                <h3 @editable("sections.{$index}.items.{$itemIndex}.title")>{{ $item['title'] ?? '' }}</h3>
                                <p @editable("sections.{$index}.items.{$itemIndex}.text", 'multiline')>{{ $item['text'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        @if (($page['type'] ?? null) === 'contact')
            @cmsFormStatus('contact')
            <form class="contact" method="POST" action="{{ route('gadya-cms.forms.store', 'contact') }}">
                @cmsForm('contact')
                <input name="name" placeholder="Your name" value="{{ old('name') }}" required>
                <input name="email" type="email" placeholder="Email" value="{{ old('email') }}" required>
                <input name="phone" placeholder="Phone (optional)" value="{{ old('phone') }}">
                <textarea name="message" rows="4" placeholder="Date, number of children, where" required>{{ old('message') }}</textarea>
                <button type="submit" data-analytics="lead_form_submit">Send</button>
            </form>
        @endif
    </article>
@endsection
