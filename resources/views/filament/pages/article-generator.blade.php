<x-filament-panels::page>
    <div class="gadya-gen" @if ($this->activeGeneration) wire:poll.4s @endif>
        @unless ($this->isConfigured)
            <p class="gadya-dash__notice">
                AI is not set up yet. Choose a service and add a key under
                <a href="{{ \Gadya\Cms\Filament\Pages\AiSettings::getUrl() }}">Settings → AI</a>, then come back here.
            </p>
        @endunless

        @if ($this->activeGeneration)
            <div class="gadya-gen__active" role="status" aria-live="polite">
                <x-filament::loading-indicator class="gadya-gen__spinner" />
                <div>
                    <p class="gadya-gen__topic">Writing: {{ $this->activeGeneration->topic }}</p>
                    <p class="gadya-dash__muted">{{ $this->activeGeneration->stage }} · attempt {{ max(1, $this->activeGeneration->attempts) }} · you can leave this page</p>
                </div>
            </div>
        @endif

        <form wire:submit="generate" class="gadya-dash__card">
            {{ $this->form }}

            <div class="gadya-gen__actions">
                <x-filament::button type="submit" icon="heroicon-o-sparkles" :disabled="(bool) $this->activeGeneration || ! $this->isConfigured">
                    Write a draft
                </x-filament::button>
                <span class="gadya-dash__muted">Drafts are never published on their own. Read it through first.</span>
            </div>
        </form>

        @if ($this->recentGenerations->isNotEmpty())
            <div class="gadya-dash__card gadya-dash__card--flush">
                <p class="gadya-dash__title">Recent requests</p>
                @foreach ($this->recentGenerations as $generation)
                    <div class="gadya-dash__row gadya-gen__row">
                        <span>
                            <span class="gadya-gen__row-topic">{{ $generation->topic }}</span>
                            <span class="gadya-dash__muted">
                                {{ $generation->created_at->diffForHumans() }}
                                @if ($generation->status === 'failed' && $generation->failure_reason)
                                    · <span class="gadya-gen__error">{{ Str::limit($generation->failure_reason, 120) }}</span>
                                @endif
                            </span>
                        </span>
                        <span class="gadya-gen__row-end">
                            <x-filament::badge :color="match ($generation->status) { 'completed' => 'success', 'failed' => 'danger', 'processing' => 'info', default => 'gray' }">
                                {{ $generation->status }}
                            </x-filament::badge>
                            @if ($generation->post)
                                <a href="{{ \Gadya\Cms\Filament\Resources\Posts\PostResource::getUrl('edit', ['record' => $generation->post]) }}">Open the draft</a>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
