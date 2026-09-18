<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @cmsSeo($page)
    <style>
        :root { --primary: {{ $site['theme']['primary'] ?? '#0f766e' }}; --secondary: {{ $site['theme']['secondary'] ?? '#f97316' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #0f172a; background: #f8fafc; line-height: 1.6; }
        a { color: var(--primary); }
        .announce { background: var(--primary); color: #fff; text-align: center; padding: .5rem 1rem; font-size: .9rem; }
        header.site { display: flex; align-items: center; justify-content: space-between; gap: 2rem; padding: 1rem 2rem; background: #fff; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; }
        header.site .brand { font-family: Georgia, serif; font-size: 1.5rem; text-decoration: none; color: var(--primary); }
        header.site nav { display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center; }
        header.site nav a { text-decoration: none; color: #0f172a; font-weight: 600; }
        header.site nav a.highlight { background: var(--secondary); color: #fff; padding: .4rem 1rem; border-radius: 999px; }
        header.site nav details { position: relative; } header.site nav summary { cursor: pointer; font-weight: 600; list-style: none; }
        header.site nav details div { position: absolute; top: 1.8rem; left: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: .5rem; padding: .5rem 1rem; display: grid; gap: .5rem; min-width: 12rem; z-index: 10; }
        main { max-width: 64rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        .page-heading, .cms-blog__heading, .cms-article__title { font-family: Georgia, serif; font-size: clamp(2rem, 5vw, 3rem); line-height: 1.1; margin: 0 0 .5rem; color: var(--primary); }
        .page-description, .cms-blog__lede, .cms-article__lede { font-size: 1.15rem; max-width: 40rem; }
        .page-hero_image, .cms-article__figure img { width: 100%; max-height: 24rem; object-fit: cover; border-radius: 1rem; margin: 1.5rem 0; }
        .page-cta { display: inline-block; background: var(--secondary); color: #fff; font-weight: 700; padding: .6rem 1.4rem; border-radius: 999px; }
        .page-section { margin-top: 3rem; } .page-section h2 { font-family: Georgia, serif; font-size: 1.75rem; margin: 0 0 .25rem; }
        .page-section__subtitle { margin: 0 0 1rem; color: #475569; }
        .page-cards, .cms-blog__grid { display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); }
        .page-card, .cms-blog__card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem; }
        .page-card img, .cms-blog__card img { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: .5rem; }
        .page-card h3 { margin: .75rem 0 .25rem; }
        .page-gallery { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); }
        .page-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: .75rem; }
        form.contact { display: grid; gap: .75rem; max-width: 28rem; margin-top: 2rem; }
        form.contact input, form.contact textarea { width: 100%; padding: .6rem .8rem; border: 1px solid #cbd5e1; border-radius: .5rem; font: inherit; }
        form.contact button { background: var(--primary); color: #fff; border: 0; padding: .7rem 1.4rem; border-radius: 999px; font-weight: 700; cursor: pointer; }
        .cms-form__success { background: #dcfce7; color: #14532d; padding: .75rem 1rem; border-radius: .5rem; }
        .cms-form__errors { background: #fee2e2; color: #7f1d1d; padding: .75rem 1.5rem; border-radius: .5rem; }
        .cms-article__body { max-width: 42rem; } .cms-article__faq details { border: 1px solid #e2e8f0; border-radius: .5rem; padding: .5rem 1rem; margin-bottom: .5rem; background: #fff; }
        footer.site { border-top: 1px solid #e2e8f0; background: #fff; padding: 2rem; text-align: center; color: #475569; font-size: .9rem; }
        .site-footer-nav { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-top: 1rem; }
        /* Categories, tags, events, search and comments: the package ships these
           class names and no styles, on purpose. This is the demo dressing them. */
        .cms-blog__terms { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 2rem; justify-content: center; }
        .cms-blog__term { padding: .3rem .9rem; border-radius: 999px; border: 1px solid #cbd5e1; text-decoration: none; font-size: .9rem; }
        .cms-blog__term--current { background: var(--primary); color: #fff; border-color: var(--primary); }
        .cms-blog__crumb, .cms-event__crumb { font-size: .85rem; text-transform: uppercase; letter-spacing: .1em; }
        .cms-article__tags { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 2rem; }
        .cms-article__tag { font-size: .85rem; color: #475569; text-decoration: none; }
        .cms-article__related h2, .cms-comments h2, .cms-events__past h2 { font-family: Georgia, serif; margin: 3rem 0 1rem; }
        .cms-events__list { list-style: none; padding: 0; display: grid; gap: 1.25rem; }
        .cms-event-card { display: grid; grid-template-columns: minmax(0, 14rem) 1fr; gap: 1.25rem; background: #fff;
                          border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden; }
        .cms-event-card__image img { width: 100%; height: 100%; object-fit: cover; }
        .cms-event-card__body { padding: 1.25rem 1.25rem 1.25rem 0; }
        .cms-event-card__when, .cms-event__when { font-weight: 700; color: var(--primary); margin: 0 0 .25rem; }
        .cms-event-card__title { font-family: Georgia, serif; font-size: 1.35rem; margin: 0 0 .35rem; }
        .cms-event-card__where, .cms-event-card__summary, .cms-event-card__price { margin: 0 0 .25rem; color: #475569; }
        .cms-event__book { display: inline-block; background: var(--secondary); color: #fff; font-weight: 700;
                           padding: .6rem 1.4rem; border-radius: 999px; text-decoration: none; margin-right: 1rem; }
        .cms-event__over { color: #b45309; font-weight: 400; }
        .cms-events__subscribe { margin-top: .5rem; }
        .cms-comment { border-top: 1px solid #e2e8f0; padding: 1rem 0; }
        .cms-comment__who { display: flex; gap: .75rem; align-items: baseline; margin: 0 0 .35rem; color: #475569; font-size: .9rem; }
        .cms-comments__form { display: grid; gap: .5rem; max-width: 32rem; margin-top: 2rem; }
        .cms-comments__form input, .cms-comments__form textarea { padding: .6rem .8rem; border: 1px solid #cbd5e1; border-radius: .5rem; font: inherit; }
        .cms-comments__form button { justify-self: start; background: var(--primary); color: #fff; border: 0;
                                     padding: .6rem 1.4rem; border-radius: 999px; font-weight: 700; cursor: pointer; }
        .cms-comments__message { background: #dcfce7; color: #14532d; padding: .75rem 1rem; border-radius: .5rem; }
        .cms-search__results { list-style: none; padding: 0; display: grid; gap: 1.5rem; margin-top: 2rem; }
        .cms-search__kind { font-size: .75rem; text-transform: uppercase; letter-spacing: .1em; color: var(--primary); margin: 0; }
        .cms-search__title { font-family: Georgia, serif; font-size: 1.25rem; margin: .15rem 0; }
        .cms-search-form { display: flex; gap: .5rem; justify-content: center; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
        .cms-search-form__input, .cms-newsletter__input { padding: .5rem .8rem; border: 1px solid #cbd5e1; border-radius: .5rem; font: inherit; min-width: 14rem; }
        .cms-search-form__button, .cms-newsletter__button { padding: .5rem 1.1rem; border: 0; border-radius: 999px;
                                                            background: var(--primary); color: #fff; font-weight: 700; cursor: pointer; }
        .cms-newsletter { margin-bottom: 1rem; }
        .cms-newsletter__row { display: flex; gap: .5rem; justify-content: center; margin-top: .35rem; }
        @media (max-width: 40rem) { .cms-event-card { grid-template-columns: 1fr; } .cms-event-card__body { padding: 0 1.25rem 1.25rem; } }
    </style>
    @if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled())
        <link rel="stylesheet" href="{{ asset('vendor/gadya-cms/editor.css') }}">
        @livewireStyles
    @endif
</head>
<body data-analytics-endpoint="{{ route('gadya-cms.events.store') }}">
    @php
        $tree = app(\Gadya\Cms\Content\NavigationTree::class);
        $menu = $tree->fromDocument($site['nav'] ?? []);
        $href = fn (array $item): string => ($item['slug'] ?? '') === 'home' ? url('/') : url('/'.($item['slug'] ?? ''));
    @endphp
    <p class="announce" @editableGlobal('announcement')>{{ $site['announcement'] ?? '' }}</p>
    <header class="site">
        <a class="brand" href="{{ url('/') }}">{{ config('gadya-cms.brand.name') }}</a>
        <nav>
            @foreach ($menu as $item)
                @if (! empty($item['children']))
                    <details><summary>{{ $item['label'] }} ▾</summary><div>
                        @foreach ($item['children'] as $child)<a href="{{ $href($child) }}">{{ $child['label'] }}</a>@endforeach
                    </div></details>
                @else
                    @php
                        $to = match ($item['slug'] ?? '') {
                            'blog-index' => url('/blog'),
                            'events-index' => url('/events'),
                            default => $href($item),
                        };
                    @endphp
                    <a href="{{ $to }}" @class(['highlight' => ! empty($item['highlight'])])>{{ $item['label'] }}</a>
                @endif
            @endforeach
        </nav>
    </header>

    <main>@yield('content')</main>

    <footer class="site">
        @cmsSearchForm
        @cmsNewsletterForm
        <p @editableGlobal('footer.tagline', 'multiline')>{{ $site['footer']['tagline'] ?? '' }}</p>
        @php $footer = app(\Gadya\Cms\Content\NavigationTree::class)->forMenu($site, 'footer'); @endphp
        @if ($footer !== [])
            <nav class="site-footer-nav">
                @foreach ($footer as $item)
                    <a href="{{ $href($item) }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif
        <p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['phone'] ?? '') }}" @editableGlobal('phone')>{{ $site['phone'] ?? '' }}</a> · <span @editableGlobal('address')>{{ $site['address'] ?? '' }}</span></p>
    </footer>

    @cmsToolbar
    @if (app(\Gadya\Cms\Editor\EditContext::class)->isEnabled())
        <script type="module" src="{{ asset('vendor/gadya-cms/editor.js') }}"></script>
        @livewireScripts
    @endif
</body>
</html>
