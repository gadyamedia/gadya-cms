<div class="gadya-preview">
    <p class="gadya-dash__muted">Anyone with this link can see the draft of this page until {{ $expires }}. They cannot change anything.</p>
    <div class="gadya-preview__row" x-data="{ copied: false }">
        <input class="gadya-preview__input" type="text" readonly value="{{ $url }}" x-ref="link" x-on:focus="$el.select()">
        <button
            type="button"
            class="gadya-preview__copy"
            x-on:click="navigator.clipboard.writeText($refs.link.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
            x-text="copied ? 'Copied' : 'Copy'"
        >Copy</button>
    </div>
</div>
