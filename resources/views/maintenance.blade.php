<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} · {{ $site }}</title>
    @if ($fontStylesheet)
        <link rel="stylesheet" href="{{ $fontStylesheet }}">
    @endif
    {{-- The site's own palette and type, from gadya-cms.brand, so a visitor
         meets the business rather than a generic error page. --}}
    <style>
        :root { --primary: {{ $primary }}; --secondary: {{ $secondary }}; --background: {{ $background }}; --ink: {{ $ink }}; --accent: {{ $accent }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem;
               font-family: ui-sans-serif, system-ui, sans-serif; background: var(--background); color: var(--ink); }
        main { max-width: 34rem; text-align: center; background: #fff; border: 4px solid #fff;
               border-radius: 2rem; padding: 3rem 2rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / .18);
               border-top: 8px solid var(--primary); }
        .logo { height: 6rem; width: auto; margin: 0 auto 1.5rem; display: block; }
        h1 { font-family: '{{ $displayFont }}', Georgia, serif; font-weight: 400; color: var(--primary);
             font-size: clamp(2rem, 6vw, 3rem); line-height: 1.1; margin: 0 0 1rem; }
        p { font-size: 1.1rem; line-height: 1.6; margin: 0 0 1rem; }
        .when { display: inline-block; background: var(--accent); padding: .35rem 1rem; border-radius: 999px; font-weight: 700; }
        form { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; margin-top: 2rem; }
        input { font: inherit; padding: .65rem 1rem; border-radius: 999px; border: 2px solid rgb(0 0 0 / .15); }
        button { font: inherit; font-weight: 700; padding: .65rem 1.4rem; border-radius: 999px; cursor: pointer;
                 background: var(--secondary); color: #fff; border: 2px solid var(--ink); box-shadow: 3px 3px 0 var(--ink); }
    </style>
</head>
<body>
    <main>
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="{{ $site }}">
        @endif
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        @if ($until)
            <p class="when">Back {{ $until->format('l j F') }} at {{ $until->format('g:ia') }}</p>
        @endif
        @if ($asks)
            <form method="GET" action="{{ url('/') }}">
                <label for="pass" hidden>Password</label>
                <input id="pass" name="pass" type="password" placeholder="Have a password?" autocomplete="off">
                <button type="submit">Let me in</button>
            </form>
        @endif
    </main>
</body>
</html>
