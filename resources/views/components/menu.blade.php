@props([
    /* The menu's short name ("lunch"), or a Menu model. */
    'menu',
    /* The heading level of the menu's name; sections and items sit below it. */
    'level' => 2,
    'images' => true,
    'schema' => true,
    'jump' => true,
])

@php
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
    $editor->boot();
    $menus = app(\Gadya\Cms\Menus\FoodMenus::class);
    $model = $menu instanceof \Gadya\Cms\Models\Menu ? $menus->find($menu->slug) : $menus->find((string) $menu);
    $level = max(1, min(4, (int) $level));
    $editUrl = $editor->isEnabled() && $model !== null
        ? rescue(fn () => \Gadya\Cms\Filament\Resources\Menus\MenuResource::getUrl('edit', ['record' => $model], panel: (string) config('gadya-cms.panel', 'admin')), null, report: false)
        : null;
@endphp

@if ($model !== null)
    @php
        $sections = $model->sections->filter(fn ($section) => $section->items->isNotEmpty());
        $usedTags = \Gadya\Cms\Menus\Dietary::for($sections->flatMap(fn ($section) => $section->items->flatMap(fn ($item) => $item->dietary ?? []))->unique()->values()->all());
    @endphp

    <section {{ $attributes->class(['cms-menu', 'cms-menu--draft' => ! $model->isLive()]) }} id="{{ $model->anchor() }}" aria-labelledby="{{ $model->anchor() }}-title" data-menu-key="{{ $model->key }}">
        <header class="cms-menu__header">
            <h{{ $level }} class="cms-menu__title" id="{{ $model->anchor() }}-title">{{ $model->name }}</h{{ $level }}>
            @if ($model->description)
                <p class="cms-menu__description">{{ $model->description }}</p>
            @endif
            @if ($model->availability)
                <p class="cms-menu__availability">{{ $model->availability }}</p>
            @endif
        </header>

        @include('gadya-cms::editor.admin-link', ['url' => $editUrl, 'label' => 'Change this menu in the admin'])

        @if ($jump && $sections->count() > 1)
            <nav class="cms-menu__jump" aria-label="{{ $model->name }} sections">
                <ul>
                    @foreach ($sections as $section)
                        <li><a href="#{{ $section->anchor() }}">{{ $section->name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @foreach ($sections as $section)
            <section class="cms-menu__section" id="{{ $section->anchor() }}" aria-labelledby="{{ $section->anchor() }}-title" data-menu-section-key="{{ $section->key }}">
                <h{{ $level + 1 }} class="cms-menu__section-title" id="{{ $section->anchor() }}-title">{{ $section->name }}</h{{ $level + 1 }}>
                @if ($section->description)
                    <p class="cms-menu__section-description">{{ $section->description }}</p>
                @endif
                @if ($section->availability)
                    <p class="cms-menu__availability">{{ $section->availability }}</p>
                @endif

                <ul class="cms-menu__items" role="list">
                    @foreach ($section->items as $item)
                        @include('gadya-cms::menus.item', ['item' => $item, 'level' => $level + 2, 'images' => $images])
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if ($usedTags !== [])
            <dl class="cms-menu__key" aria-label="Key to the dietary marks">
                @foreach ($usedTags as $key => $tag)
                    <div class="cms-menu__key-entry cms-menu__key-entry--{{ $key }}">
                        <dt>{{ $tag['short'] }}</dt>
                        <dd>{{ $tag['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($schema && $model->isLive())
            <script type="application/ld+json">{!! json_encode($menus->structuredData($model), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endif
    </section>
@elseif ($editor->isEnabled())
    <p class="gadya-cms-admin-link">No published menu called “{{ $menu instanceof \Gadya\Cms\Models\Menu ? $menu->slug : $menu }}” yet. Add it under Food menus in the admin.</p>
@endif
