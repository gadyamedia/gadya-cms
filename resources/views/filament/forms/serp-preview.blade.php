@php
    $path = $path ?? '';
    $urlPrefix = $urlPrefix ?? '';
    $record = $getRecord();
    $state = $this->form?->getRawState() ?? [];

    $title = trim(strip_tags((string) (data_get($state, $path.'meta_title') ?: (data_get($state, 'title') ?: $record?->title ?: ''))));
    $description = trim(strip_tags((string) (data_get($state, $path.'meta_description') ?: (data_get($state, 'excerpt') ?: (data_get($state, 'draft.description') ?: $record?->excerpt ?: '')))));
    $slug = (string) (data_get($state, 'slug') ?: $record?->slug ?: '');
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';
@endphp

{{-- Roughly how it looks in a results page. Google rewrites titles
     freely, but this catches one that is far too long or missing. --}}
<div class="gadya-serp">
    <p class="gadya-serp__url">{{ $host }}{{ $urlPrefix !== '' ? ' › '.trim($urlPrefix, '/') : '' }} › {{ $slug ?: 'your-page' }}</p>
    <p class="gadya-serp__title">{{ $title !== '' ? Str::limit($title, 65) : 'Untitled' }}</p>
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
