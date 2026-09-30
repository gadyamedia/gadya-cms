@if (filled($greeting ?? null)){{ $greeting }}

@endif
@foreach ($introLines ?? [] as $line)
{{ strip_tags(preg_replace('/\*\*(.+?)\*\*/', '$1', $line)) }}

@endforeach
@isset($actionText)
{{ $actionText }}: {{ $actionUrl }}

@endisset
@foreach ($outroLines ?? [] as $line)
{{ strip_tags(preg_replace('/\*\*(.+?)\*\*/', '$1', $line)) }}

@endforeach
@if (filled($salutation ?? null)){{ $salutation }}

@endif
--
Emails powered by Gadya - https://gadya.media
