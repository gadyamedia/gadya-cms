{{--
    A way back to the real site from the admin. The client spends most of
    her time on the page itself, so the door between the two should never
    be hard to find.
--}}
<div class="fi-sidebar-footer px-6 py-4">
    <a
        class="fi-link fi-size-sm flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400"
        href="{{ url('/') }}"
        target="_blank"
        rel="noopener"
    >
        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare) }}
        <span>View the site</span>
    </a>
</div>
