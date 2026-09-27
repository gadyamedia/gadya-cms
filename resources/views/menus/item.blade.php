@php
    $prices = $item->prices();
    $tags = \Gadya\Cms\Menus\Dietary::for($item->dietary);
    $level = min(6, $level);
@endphp

<li @class(['cms-menu-item', 'cms-menu-item--featured' => $item->is_featured, 'cms-menu-item--sold-out' => $item->is_sold_out]) data-menu-item-key="{{ $item->key }}">
    @if ($images && $item->image)
        <img class="cms-menu-item__image" src="@siteImage($item->image, 480)" alt="{{ $item->image_alt ?? '' }}" loading="lazy">
    @endif

    <div class="cms-menu-item__body">
        <h{{ $level }} class="cms-menu-item__name">
            {{ $item->name }}
            @if ($item->is_featured)
                <span class="cms-menu-item__badge">Special</span>
            @endif
        </h{{ $level }}>

        @if ($item->description)
            <p class="cms-menu-item__description">{{ $item->description }}</p>
        @endif

        @if ($tags !== [])
            <ul class="cms-menu-item__tags" role="list" aria-label="Dietary information">
                @foreach ($tags as $key => $tag)
                    <li class="cms-menu-item__tag cms-menu-item__tag--{{ $key }}" title="{{ $tag['label'] }}">
                        <span aria-hidden="true">{{ $tag['short'] }}</span>
                        <span class="cms-visually-hidden" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">{{ $tag['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($item->availability)
            <p class="cms-menu-item__availability">{{ $item->availability }}</p>
        @endif
    </div>

    <div class="cms-menu-item__offer">
        @if (count($prices) === 1 && $prices[0]['label'] === null)
            <p class="cms-menu-item__price">{{ \Gadya\Cms\Menus\Price::format($prices[0]['price_cents']) }}</p>
        @elseif ($prices !== [])
            <dl class="cms-menu-item__prices">
                @foreach ($prices as $price)
                    <div class="cms-menu-item__size" data-menu-variant-key="{{ $price['key'] }}">
                        <dt>{{ $price['label'] }}</dt>
                        <dd>{{ \Gadya\Cms\Menus\Price::format($price['price_cents']) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($item->is_sold_out)
            <p class="cms-menu-item__sold-out"><strong>Sold out</strong></p>
        @endif
    </div>
</li>
