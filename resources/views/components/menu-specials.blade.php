@props([
    'heading' => 'Specials',
    'level' => 2,
    'limit' => 6,
    'images' => true,
])

@php
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
    $editor->boot();
    $specials = app(\Gadya\Cms\Menus\FoodMenus::class)->featured((int) $limit);
    $level = max(1, min(5, (int) $level));
@endphp

@if ($specials->isNotEmpty())
    <section {{ $attributes->class(['cms-menu-specials']) }} aria-labelledby="cms-menu-specials-title">
        <h{{ $level }} class="cms-menu-specials__title" id="cms-menu-specials-title">{{ $heading }}</h{{ $level }}>
        <ul class="cms-menu__items" role="list">
            @foreach ($specials as $item)
                @include('gadya-cms::menus.item', ['item' => $item, 'level' => $level + 1, 'images' => $images])
            @endforeach
        </ul>
    </section>
@endif
