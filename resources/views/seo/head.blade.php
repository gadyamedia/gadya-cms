<title>{{ $tags['title'] }}</title>
<meta name="description" content="{{ $tags['description'] }}">
<meta name="robots" content="{{ $tags['robots'] }}">
<link rel="canonical" href="{{ $tags['canonical'] }}">
<meta property="og:type" content="{{ $tags['type'] }}">
<meta property="og:site_name" content="{{ $tags['site_name'] }}">
<meta property="og:title" content="{{ $tags['title'] }}">
<meta property="og:description" content="{{ $tags['description'] }}">
<meta property="og:url" content="{{ $tags['canonical'] }}">
@if ($tags['image'])
<meta property="og:image" content="{{ $tags['image'] }}">
<meta name="twitter:card" content="summary_large_image">
@else
<meta name="twitter:card" content="summary">
@endif
<meta name="twitter:title" content="{{ $tags['title'] }}">
<meta name="twitter:description" content="{{ $tags['description'] }}">
@foreach ($structured ?? [] as $node)
<script type="application/ld+json">{!! json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endforeach
