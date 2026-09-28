{{--
    A built form inside an iframe on another site. Nothing but the form, a
    transparent background, and a message to the page around it saying how
    tall it is (see the snippet under the form's Share tab).
--}}
<!DOCTYPE html>
<html lang="@cmsLang">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $form->title }}</title>
    <style @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
        body { margin: 0; padding: 1rem; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.5; background: transparent; }
    </style>
</head>
<body>
    {{ $html }}
</body>
</html>
