<!DOCTYPE html>
<html lang="@cmsLang">
<head>@cmsSeo($page)<link rel="stylesheet" href="{{ asset('build/app.css') }}"></head>
<body>
<header>
    <p>{{ $site['announcement'] ?? '' }}</p>
    <nav class="menu">
        @foreach ($site['nav'] ?? [] as $item)
            @if (isset($item['slug']))<a href="{{ url($item['slug'] === 'home' ? '/' : '/'.$item['slug']) }}">{{ $item['label'] }}</a>@endif
        @endforeach
    </nav>
    @cmsLanguageSwitcher
</header>
<main>@yield('content')</main>
@cmsToolbar
</body>
</html>
