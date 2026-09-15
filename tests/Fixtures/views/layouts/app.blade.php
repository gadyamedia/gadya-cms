<!DOCTYPE html>
<html lang="en">
<head><title>{{ $page['title'] }}</title></head>
<body>
<header>{{ $site['announcement'] ?? '' }}</header>
<main>@yield('content')</main>
@cmsToolbar
</body>
</html>
