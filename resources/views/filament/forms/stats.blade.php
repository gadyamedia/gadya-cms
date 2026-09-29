<x-filament-panels::page>
    @php
        $form = $this->record;
        $stats = app(\Gadya\Cms\Analytics\FormAnalytics::class)->for($form, $this->days);
        $deliveries = $form->deliveries()->latest('id')->limit(10)->get();
        $time = fn (?int $seconds): string => $seconds === null ? '—' : ($seconds < 90 ? $seconds.' sec' : round($seconds / 60, 1).' min');
    @endphp

    <div class="gadya-dash">
        <div class="gadya-dash__toolbar">
            <p>{{ $form->isLive() ? 'Live' : 'Not live' }} · version {{ $form->version }}</p>
            <div class="gadya-dash__range" role="group" aria-label="Date range">
                @foreach ([7, 30, 90] as $option)
                    <button type="button" wire:click="setRange({{ $option }})" aria-pressed="{{ $this->days === $option ? 'true' : 'false' }}">{{ $option }} days</button>
                @endforeach
            </div>
        </div>

        <div class="gadya-dash__tiles">
            @foreach ([
                ['label' => 'Saw it', 'value' => number_format($stats['views']), 'hint' => 'People, counted once a day each'],
                ['label' => 'Started', 'value' => number_format($stats['starts']), 'hint' => $stats['start_rate'].'% of those who saw it'],
                ['label' => 'Sent', 'value' => number_format($stats['completions']), 'hint' => 'Enquiries that arrived'],
                ['label' => 'Conversion', 'value' => $stats['conversion'].'%', 'hint' => 'Sent, of those who saw it'],
                ['label' => 'Time to fill in', 'value' => $time($stats['average_seconds']), 'hint' => 'On average'],
            ] as $tile)
                <div class="gadya-dash__tile">
                    <p class="gadya-dash__tile-label">{{ $tile['label'] }}</p>
                    <p class="gadya-dash__tile-value">{{ $tile['value'] }}</p>
                    <p class="gadya-dash__tile-hint">{{ $tile['hint'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($stats['steps'] !== [])
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Where people stop</p><span>Reached · gave up here</span></div>
                @foreach ($stats['steps'] as $step)
                    <div class="gadya-dash__row"><span>{{ $step['step'] }}. {{ $step['title'] }}</span><span>{{ number_format($step['people']) }} · {{ number_format($step['dropped']) }}</span></div>
                @endforeach
            </div>
        @endif

        <div class="gadya-dash__grid">
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">On these pages</p><span>Times</span></div>
                @forelse ($stats['pages'] as $path => $count)
                    <div class="gadya-dash__row"><span>{{ $path }}</span><span>{{ number_format($count) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Nobody has seen it yet.</p>
                @endforelse
            </div>
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Arriving from</p><span>Times</span></div>
                @forelse ($stats['sources'] as $host => $count)
                    <div class="gadya-dash__row"><span>{{ $host }}</span><span>{{ number_format($count) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Everyone came from this site, or from a link with no address to show.</p>
                @endforelse
            </div>
        </div>

        @if ($deliveries->isNotEmpty())
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Webhooks lately</p><span>Result</span></div>
                @foreach ($deliveries as $delivery)
                    <div class="gadya-dash__row">
                        <span>{{ $delivery->created_at?->diffForHumans() }} · {{ \Illuminate\Support\Str::limit($delivery->url, 60) }}</span>
                        <span>{{ match ($delivery->status) { 'succeeded' => 'Delivered', 'failed' => 'Failed'.($delivery->error ? ': '.$delivery->error : ''), default => 'Trying ('.$delivery->attempts.')' } }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="gadya-dash__footnote">Counted on your own site, like the rest of the dashboard. Someone who turned analytics off in the privacy banner is not counted seeing or starting a form.</p>
    </div>
</x-filament-panels::page>
