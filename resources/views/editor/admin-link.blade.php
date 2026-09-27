{{--
    Structured content - a menu, the opening hours - is edited in the
    admin, not on the page. For someone editing, this says where.
--}}
@if ($url)
    <p class="gadya-cms-admin-link"><a class="gadya-cms-link" href="{{ $url }}" target="_blank" rel="noopener">{{ $label }}</a></p>
@endif
