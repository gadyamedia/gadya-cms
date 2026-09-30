<div x-data="{ zone: Intl.DateTimeFormat().resolvedOptions().timeZone }">
    <button
        type="button"
        style="background:none;border:0;padding:0;cursor:pointer;font-size:.875rem;font-weight:500;text-decoration:underline;color:var(--primary-600, #0f766e);"
        x-on:click.prevent="$wire.set('timezoneData.timezone', zone)"
    >
        Use this device's time zone<span x-text="zone ? ' (' + zone + ')' : ''"></span>
    </button>
</div>
