{{--
    A built form on a page of its own - the address to put in a text, an
    email or a QR code. Plain on purpose; set forms.builder.layout to the
    site's own layout to wear its header and footer instead.
--}}
<!DOCTYPE html>
<html lang="@cmsLang">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @cmsSeo($page)
    <style @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.5; background: #f8fafc; }
        .cms-form-page { max-width: 44rem; margin: 0 auto; padding: 2rem 1rem 4rem; }
        .cms-form-page__brand { margin: 0 0 1.5rem; font-weight: 700; }
        .cms-form-page__brand a { color: inherit; text-decoration: none; }
        .cms-form-page__card { background: #fff; border-radius: 1rem; padding: clamp(1rem, 4vw, 2rem); box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
        .cms-form-page h1 { margin: 0 0 .5rem; font-size: clamp(1.5rem, 4vw, 2rem); line-height: 1.2; }
        .cms-form-page__description { margin: 0 0 1.5rem; }
    </style>
</head>
<body>
    <main class="cms-form-page">
        <p class="cms-form-page__brand"><a href="{{ url('/') }}">{{ config('gadya-cms.brand.name', config('app.name')) }}</a></p>
        <div class="cms-form-page__card">
            <h1>{{ $form->title }}</h1>
            @if (filled($form->description))
                <p class="cms-form-page__description">{{ $form->description }}</p>
            @endif
            {{ app(\Gadya\Cms\Forms\Builder\FormRenderer::class)->render($form) }}
        </div>
    </main>
    @cmsToolbar
</body>
</html>
