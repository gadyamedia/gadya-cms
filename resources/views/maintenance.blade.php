<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem;
               font-family: ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 32rem; text-align: center; }
        h1 { font-size: clamp(1.75rem, 5vw, 2.5rem); margin: 0 0 1rem; }
        p { font-size: 1.05rem; line-height: 1.6; margin: 0 0 1rem; color: #334155; }
        form { display: flex; gap: .5rem; justify-content: center; margin-top: 2rem; }
        input, button { font: inherit; padding: .6rem .9rem; border-radius: .5rem; border: 1px solid #cbd5e1; }
        button { background: #0f172a; color: #fff; border-color: #0f172a; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        @if ($until)
            <p>We expect to be back on {{ $until->format('l j F') }} at {{ $until->format('g:ia') }}.</p>
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
