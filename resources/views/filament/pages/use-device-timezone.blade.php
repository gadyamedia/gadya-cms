<div x-data="{ zone: Intl.DateTimeFormat().resolvedOptions().timeZone }">
    <button
        type="button"
        class="fi-link text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        x-on:click.prevent="$wire.set('timezoneData.timezone', zone)"
    >
        Use this device's time zone<span x-text="zone ? ' (' + zone + ')' : ''"></span>
    </button>
</div>
