<x-filament-panels::page>
    <div class="gadya-dash">
        @if (count(app(\Gadya\Cms\Localisation\Locales::class)->additional()) > 1)
            <div class="gadya-dash__range" role="group" aria-label="Language">
                @foreach (app(\Gadya\Cms\Localisation\Locales::class)->additional() as $code)
                    <button type="button" wire:click="$set('locale', '{{ $code }}')" aria-pressed="{{ $code === $this->locale ? 'true' : 'false' }}">
                        {{ app(\Gadya\Cms\Localisation\Locales::class)->name($code) }}
                    </button>
                @endforeach
            </div>
        @endif

        @unless ($this->canTranslate())
            <div class="gadya-dash__notice">
                <p>Machine translation needs AI: add a key under <strong>Settings → AI</strong>, or pair the site with Gadya Media. Translations can still be typed in by hand on the page.</p>
            </div>
        @endunless

        <div class="gadya-dash__card gadya-dash__card--flush">
            <p class="gadya-dash__title">{{ $this->languageName() }}</p>
            @foreach ($this->rows as $row)
                <div class="gadya-dash__row" wire:key="translation-{{ $row['key'] }}">
                    <span>
                        <strong>{{ $row['label'] }}</strong>
                        <span class="gadya-dash__muted">· {{ $row['kind'] }} · {{ $this->statusLabel($row['status']) }}</span>
                    </span>
                    <span class="gadya-gen__row-end">
                        @if ($row['status'] === \Gadya\Cms\Localisation\TranslateContent::STATUS_REVIEW)
                            {{ ($this->approveAction)(['key' => $row['key']]) }}
                        @endif
                        @if ($row['path'] !== null)
                            {{ ($this->editOnPageAction)(['path' => $row['path']]) }}
                        @endif
                        {{ ($this->translateAction)(['key' => $row['key'], 'status' => $row['status']]) }}
                    </span>
                </div>
            @endforeach
        </div>

        {{ $this->form }}
    </div>
</x-filament-panels::page>
