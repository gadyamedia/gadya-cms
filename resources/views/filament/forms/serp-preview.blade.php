@php
    $record = $getRecord();
    $state = $this->form?->getRawState() ?? [];

    $title = trim(strip_tags((string) ($state['meta_title'] ?: ($record?->meta_title ?: ($state['title'] ?? $record?->title ?? '')))));
    $description = trim(strip_tags((string) ($state['meta_description'] ?: ($record?->meta_description ?: ($state['excerpt'] ?? $record?->excerpt ?? '')))));
    $slug = (string) ($state['slug'] ?? $record?->slug ?? '');
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';
    $prefix = trim((string) config('gadya-cms.blog.prefix', 'blog'), '/');
@endphp

{{-- Roughly how the article looks in a results page. Google rewrites
     titles freely, but this catches one that is far too long or missing. --}}
<div class="gadya-serp">
    <p class="gadya-serp__url">{{ $host }} › {{ $prefix }} › {{ $slug ?: 'your-article' }}</p>
    <p class="gadya-serp__title">{{ $title !== '' ? Str::limit($title, 65) : 'Untitled article' }}</p>
    <p class="gadya-serp__description">
        {{ $description !== '' ? Str::limit($description, 160) : 'No description yet. Google picks a sentence itself, which is rarely the one you would choose.' }}
    </p>
</div>

@if ($title !== '' && strlen($title) > 65)
    <p class="gadya-serp__warning">The title is {{ strlen($title) }} characters; Google cuts it off around 65.</p>
@endif

@if ($description !== '' && strlen($description) > 160)
    <p class="gadya-serp__warning">The description is {{ strlen($description) }} characters; Google cuts it off around 160.</p>
@endif
