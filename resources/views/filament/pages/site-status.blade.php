<x-filament-panels::page>
    <div class="gadya-dash">
        @if ($this->scheduledPublish)
            <p class="gadya-dash__notice">Everything in your draft goes live on {{ $this->scheduledPublish }}. You can change or cancel that from any Publish changes button.</p>
        @endif

        {{ $this->form }}

        @if ($this->shareUrl)
            <div class="gadya-dash__card">
                <p class="gadya-dash__title">A link for whoever needs a look</p>
                <p class="gadya-dash__muted">It carries the password, so they see the site without signing in and without being told the password itself.</p>
                <div class="gadya-preview__row">
                    <input class="gadya-preview__input" type="text" readonly value="{{ $this->shareUrl }}" onfocus="this.select()">
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
