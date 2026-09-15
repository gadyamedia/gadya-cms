<x-filament-panels::page>
    @php
        $report = $this->report;
        $headline = $report->headline();
        $daily = $report->daily();
        $live = $report->live();
        $titles = $this->pageTitles;
        $name = fn (string $path): string => $titles[$path] ?? $path;
        $peak = max(1, collect($daily)->max('views'));
    @endphp

    <div class="gadya-dash">
        @foreach ($this->notices as $notice)
            <p class="gadya-dash__notice">{{ $notice }}</p>
        @endforeach

        {{-- Who is on the site right now. Polled rather than pushed: a party
             site does not need a socket open all day to answer this. --}}
        <div class="gadya-dash__card" wire:poll.20s>
            <div class="gadya-dash__live-head">
                <p class="gadya-dash__live-label">
                    <span @class(['gadya-dash__dot', 'gadya-dash__dot--on' => $live['count'] > 0])><span></span></span>
                    {{ $live['count'] === 0 ? 'Nobody on the site right now' : $live['count'].' '.Str::plural('person', $live['count']).' on the site now' }}
                </p>
                <p class="gadya-dash__muted">Counted here on your own site — no cookies, no Google, no visitor's address stored.</p>
            </div>

            @if ($live['count'] > 0)
                <div class="gadya-dash__columns">
                    <div>
                        <p class="gadya-dash__subtitle">Looking at</p>
                        @foreach ($live['pages'] as $row)
                            <div class="gadya-dash__row"><span>{{ $name($row['path']) }}</span><span>{{ $row['visitors'] }}</span></div>
                        @endforeach
                    </div>
                    <div>
                        <p class="gadya-dash__subtitle">Where from</p>
                        @forelse ($live['countries'] as $row)
                            <div class="gadya-dash__row"><span>{{ $row['flag'] }} {{ $row['code'] }}</span><span>{{ $row['visitors'] }}</span></div>
                        @empty
                            <p class="gadya-dash__muted">Town and country show up on the live site, where the CDN tells us — never here on your own machine.</p>
                        @endforelse
                        @foreach ($live['places'] as $row)
                            <div class="gadya-dash__row"><span>{{ $row['label'] }}</span><span>{{ $row['visitors'] }}</span></div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="gadya-dash__range">
            <span>Showing the last</span>
            @foreach ($this->getRangeOptions() as $option)
                <button type="button" wire:click="setRange({{ $option }})" aria-pressed="{{ $this->days === $option ? 'true' : 'false' }}">{{ $option }} days</button>
            @endforeach
        </div>

        <div class="gadya-dash__tiles">
            @foreach ([
                ['label' => 'Visits', 'value' => number_format($headline['views']), 'hint' => 'Pages opened'],
                ['label' => 'People', 'value' => number_format($headline['visitors']), 'hint' => 'Counted once a day each'],
                ['label' => 'Phone taps', 'value' => number_format($headline['phone_clicks']), 'hint' => 'Tapped your number'],
                ['label' => 'Enquiries', 'value' => number_format($headline['enquiries']), 'hint' => 'Started a booking or form'],
                ['label' => 'Got in touch', 'value' => $headline['contact_rate'].'%', 'hint' => 'Of everyone who visited'],
            ] as $tile)
                <div class="gadya-dash__card">
                    <p class="gadya-dash__tile-label">{{ $tile['label'] }}</p>
                    <p class="gadya-dash__tile-value">{{ $tile['value'] }}</p>
                    <p class="gadya-dash__tile-hint">{{ $tile['hint'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- A bar per day, quiet days kept as gaps rather than closed up. --}}
        <div class="gadya-dash__card">
            <p class="gadya-dash__title">Visits per day</p>
            <div class="gadya-dash__chart" role="img" aria-label="Visits per day over the last {{ $this->days }} days">
                @foreach ($daily as $day)
                    <div
                        class="gadya-dash__bar"
                        style="height: {{ max(2, round($day['views'] / $peak * 100)) }}%"
                        title="{{ $day['day'] }} — {{ $day['views'] }} visits from {{ $day['visitors'] }} people"
                    ></div>
                @endforeach
            </div>
            <div class="gadya-dash__axis">
                <span>{{ $daily[0]['day'] ?? '' }}</span>
                <span>{{ collect($daily)->last()['day'] ?? '' }}</span>
            </div>
        </div>

        @php $countries = $report->countryTotals(); @endphp
        @if ($countries !== [])
            <div class="gadya-dash__card">
                <p class="gadya-dash__title">Where visitors come from</p>

                @if ($this->worldMap)
                    {{-- A public-domain SVG served from this box: no tile
                         server, no third party, recoloured per country. --}}
                    @php $peakCountry = max(1, ...array_values($countries)); @endphp
                    <style>
                        .gadya-dash__map svg { width: 100%; height: auto; }
                        .gadya-dash__map .landxx { fill: var(--gadya-dash-chip); stroke: var(--gadya-dash-surface); stroke-width: .5; }
                        @foreach ($countries as $code => $count)
                        .gadya-dash__map .{{ $code }} { fill: color-mix(in srgb, var(--gadya-cms-primary) {{ round(25 + 75 * $count / $peakCountry) }}%, transparent); }
                        @endforeach
                    </style>
                    <div class="gadya-dash__map">{!! $this->worldMap !!}</div>
                @endif

                <div class="gadya-dash__countries">
                    @foreach (collect($countries)->sortDesc()->take(12) as $code => $count)
                        <span>{{ \Gadya\Cms\Analytics\VisitorGeo::flag(strtoupper($code)) }} {{ strtoupper($code) }} <strong>{{ number_format($count) }}</strong></span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="gadya-dash__columns gadya-dash__columns--three" style="margin-top: 0">
            <div class="gadya-dash__card gadya-dash__card--flush">
                <p class="gadya-dash__title">Most visited pages</p>
                @forelse ($report->topPages() as $page)
                    <div class="gadya-dash__row"><span>{{ $name($page->path) }}</span><span>{{ number_format($page->views) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Nothing yet.</p>
                @endforelse
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <p class="gadya-dash__title">How they found you</p>
                @forelse ($report->referrers() as $referrer)
                    <div class="gadya-dash__row"><span>{{ $referrer->referrer_host }}</span><span>{{ number_format($referrer->visitors) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Everyone so far came straight to the site.</p>
                @endforelse
                @foreach ($report->campaigns() as $campaign)
                    <div class="gadya-dash__row"><span>{{ $campaign->utm_source }}{{ $campaign->utm_campaign ? ' · '.$campaign->utm_campaign : '' }}</span><span>{{ number_format($campaign->visitors) }}</span></div>
                @endforeach
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <p class="gadya-dash__title">What they did</p>
                @forelse ($report->events() as $event)
                    <div class="gadya-dash__row"><span>{{ Str::headline($event->name) }}</span><span>{{ number_format($event->total) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Nothing yet.</p>
                @endforelse

                <p class="gadya-dash__title" style="border-top: 1px solid var(--gadya-dash-line)">On what</p>
                @forelse ($report->devices() as $device)
                    <div class="gadya-dash__row"><span>{{ $device['label'] }}</span><span>{{ $device['share'] }}%</span></div>
                @empty
                    <p class="gadya-dash__empty">Nothing yet.</p>
                @endforelse
            </div>
        </div>

        <div class="gadya-dash__card gadya-dash__card--flush">
            <p class="gadya-dash__title">Recently published</p>
            @forelse ($this->recentPublishes as $revision)
                <div class="gadya-dash__row">
                    <span>{{ $revision->label ?? 'Changes published' }}</span>
                    <span>{{ $revision->publisher?->name ?? 'Someone' }} · {{ $revision->published_at?->diffForHumans() }}</span>
                </div>
            @empty
                <p class="gadya-dash__empty">Nothing published yet.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
