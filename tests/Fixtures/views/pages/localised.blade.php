@extends('layouts.localised')

@section('content')
    @editableFor("pages.{$slug}")
    <h1 @editable('heading')>{{ $page['heading'] ?? '' }}</h1>
    <p class="description" @editable('description', 'multiline')>{{ $page['description'] ?? '' }}</p>
    @foreach ($page['sections'] ?? [] as $index => $section)
        <h2 @editable("sections.{$index}.title")>{{ $section['title'] ?? '' }}</h2>
        @foreach ($section['items'] ?? [] as $itemIndex => $item)
            <h3 @editable("sections.{$index}.items.{$itemIndex}.title")>{{ $item['title'] ?? '' }}</h3>
        @endforeach
    @endforeach
@endsection
