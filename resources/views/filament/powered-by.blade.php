{{-- Under every panel screen, the sign-in page included. --}}
<p class="gadya-powered">
    Site powered with <span class="gadya-powered__heart" aria-label="love">❤</span> by
    <a href="https://gadya.media" target="_blank" rel="noopener">Gadya CMS</a>@if ($version)<span class="gadya-powered__version">{{ ctype_digit($version[0]) ? 'v'.$version : $version }}</span>@endif
    by <a href="https://gadya.media" target="_blank" rel="noopener">gadya.media</a>
</p>
