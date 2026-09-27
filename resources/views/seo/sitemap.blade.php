{{-- Assembled from pieces: a literal "<?" in a Blade file is taken for a PHP tag on servers with short tags on. --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
@if (collect($entries)->contains(fn ($entry) => ! empty($entry['alternates'])))
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@else
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@endif
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@if ($entry['lastmod'])
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
        <priority>{{ $entry['priority'] }}</priority>
@foreach ($entry['alternates'] ?? [] as $hreflang => $href)
        <xhtml:link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}"/>
@endforeach
    </url>
@endforeach
</urlset>
