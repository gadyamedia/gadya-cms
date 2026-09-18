<!DOCTYPE html>
<html lang="en">
<head>@cmsSeo($page)</head>
<body>
<header>{{ $site['announcement'] ?? '' }}</header>
<main>@yield('content')</main>
@cmsToolbar
</body>
</html>
