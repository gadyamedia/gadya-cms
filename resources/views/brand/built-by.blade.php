{{--
    The Gadya Media badge: sits at the end of the footer, in the site's ink.
    Rendered here rather than by a script from gadya.media, so it costs the
    page no extra script and no web-font request: the text uses Arvo when
    the site already loads it, Georgia otherwise, and the logo loads lazily.
--}}
<div class="gadya-built-by" style="display:flex;justify-content:{{ $justify }};">
    <a href="https://gadya.media" target="_blank" rel="noopener" aria-label="Website built by Gadya Media" style="display:inline-flex;align-items:flex-start;gap:6px;color:{{ $color }};text-decoration:none;white-space:nowrap;line-height:1;text-transform:none;letter-spacing:normal;">
        <span style="display:inline-block;margin-top:2px;color:inherit;font-family:Arvo,Georgia,serif;font-size:10px;font-style:italic;font-weight:700;line-height:1;text-transform:lowercase;letter-spacing:normal;">built by</span>
        <img src="https://gadya.media/brand/gadya-logo.png" alt="Gadya Media" width="{{ $logoWidth }}" height="{{ $logoHeight }}" loading="lazy" decoding="async" style="display:block;width:auto;height:{{ $logoHeight }}px;max-width:none;opacity:.9;filter:{{ $filter }};">
    </a>
</div>
