<x-filament-panels::page>
    <div class="gadya-dash">
        @if ($this->blocks === [])
            <div class="gadya-dash__card">
                <p class="gadya-dash__title">Nothing saved yet</p>
                <p class="gadya-dash__muted">
                    On any page, open a section and choose <strong>Save as a block</strong>. It appears here, and
                    <strong>Insert a saved block</strong> drops a copy of it into any other page.
                </p>
            </div>
        @else
            <div class="gadya-dash__card gadya-dash__card--flush">
                <p class="gadya-dash__title">{{ count($this->blocks) }} saved {{ Str::plural('block', count($this->blocks)) }}</p>
                @foreach ($this->blocks as $key => $block)
                    <div class="gadya-dash__row">
                        <span>
                            <strong>{{ $block['label'] ?? $key }}</strong>
                            <span class="gadya-dash__muted">· {{ $this->describe($key) }}</span>
                        </span>
                        <span class="gadya-gen__row-end">
                            {{ ($this->renameAction)(['key' => $key]) }}
                            {{ ($this->forgetAction)(['key' => $key]) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
