{{--
    The email that tells staff about a new enquiry, under Gadya's name: the
    logo on a dark band (it is a white logo), the enquiry in a plain card,
    and "Emails powered by Gadya" at the foot. Inline styles and tables
    throughout, because that is all every mail program agrees on.

    The marker on the footer tells BrandsOutgoingMail its own line is
    already here, so the foot never says it twice.
--}}
@php
    $ink = '#0b1220';
    $muted = '#6b7280';
    $paragraph = 'margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;';

    /*
     * Everything a visitor typed reaches this line, so it is escaped first
     * and only the bold on a label is turned into markup - no links, no
     * pictures, nothing else they could type would be drawn.
     */
    $format = fn (string $line): string => nl2br((string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($line)));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject ?? 'New enquiry' }}</title>
    <style>
        p { margin: 0 0 14px; }
        a { color: #0f766e; }
        @media only screen and (max-width: 620px) { .gadya-card { padding: 22px 18px !important; } }
    </style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;border-collapse:collapse;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;border-collapse:collapse;">
                <tr>
                    <td align="center" bgcolor="{{ $ink }}" style="background:{{ $ink }};padding:22px 24px;border-radius:12px 12px 0 0;">
                        <a href="https://gadya.media?utm_source=client-email" style="text-decoration:none;">
                            <img src="https://gadya.media/brand/gadya-logo.png" alt="Gadya" width="132" height="33" style="display:block;border:0;width:132px;height:auto;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;">
                        </a>
                    </td>
                </tr>
                <tr>
                    <td class="gadya-card" bgcolor="#ffffff" style="background:#ffffff;padding:30px 32px;border-radius:0 0 12px 12px;">
                        @if (filled($greeting ?? null))
                            <h1 style="margin:0 0 18px;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;font-weight:bold;color:{{ $ink }};">{{ $greeting }}</h1>
                        @endif

                        @foreach ($introLines ?? [] as $line)
                            <div style="{{ $paragraph }}">{!! $format($line) !!}</div>
                        @endforeach

                        @isset($actionText)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 22px;border-collapse:collapse;">
                                <tr>
                                    <td bgcolor="{{ $ink }}" style="background:{{ $ink }};border-radius:8px;">
                                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">{{ $actionText }}</a>
                                    </td>
                                </tr>
                            </table>
                        @endisset

                        @foreach ($outroLines ?? [] as $line)
                            <div style="{{ $paragraph }}">{!! $format($line) !!}</div>
                        @endforeach

                        @if (filled($salutation ?? null))
                            <p style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;color:{{ $muted }};">{{ $salutation }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td align="center" data-gadya-mail style="padding:18px 8px 6px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#8a8f98;">
                        Emails powered by <a href="https://gadya.media?utm_source=client-email" style="color:#8a8f98;text-decoration:underline;">Gadya</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
