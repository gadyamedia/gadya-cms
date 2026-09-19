<div x-data="{ copied: false }" class="gadya-credentials">
    <textarea class="gadya-credentials__text" x-ref="text" readonly rows="10" x-on:focus="$el.select()">{{ $text }}</textarea>
    <button
        type="button"
        class="gadya-preview__copy gadya-credentials__copy"
        x-on:click="navigator.clipboard.writeText($refs.text.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
        x-text="copied ? 'Copied' : 'Copy'"
    >Copy</button>
</div>
