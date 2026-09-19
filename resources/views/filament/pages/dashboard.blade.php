<x-filament-panels::page>
    @php
        $report = $this->report;
        $headline = $report->headline();
        $daily = $report->daily();
        $live = $report->live();
        $titles = $this->pageTitles;
        $name = fn (string $path): string => $titles[$path] ?? $path;
        $peak = max(1, collect($daily)->max('views'));
        $share = fn (int|float $value, int|float $of): string => round($of > 0 ? $value / $of * 100 : 0, 1).'%';
        $topPages = $report->topPages(8);
        $referrers = $report->referrers();
        $campaigns = $report->campaigns();
        $events = $report->events();
        $devices = $report->devices();
        $countries = $report->countryTotals();
        $notices = $this->notices;
        $livePlaces = $live['places'] !== []
            ? $live['places']
            : collect($live['countries'])->map(fn (array $row): array => ['label' => $row['flag'].' '.\Gadya\Cms\Analytics\VisitorGeo::countryName($row['code']), 'visitors' => $row['visitors']])->all();
    @endphp

    <div class="gadya-dash">
        @if ($notices !== [])
            <div class="gadya-dash__notice" role="status">
                @if (count($notices) === 1)
                    <p>{{ $notices[0] }}</p>
                @else
                    <ul>
                        @foreach ($notices as $notice)
                            <li>{{ $notice }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Who is on the site right now. Polled rather than pushed: a party
             site does not need a socket open all day to answer this. --}}
        <div class="gadya-dash__toolbar">
            <p class="gadya-dash__live" wire:poll.20s>
                <span @class(['gadya-dash__dot', 'gadya-dash__dot--on' => $live['count'] > 0])><span></span></span>
                {{ $live['count'] === 0 ? 'Nobody on the site right now' : $live['count'].' '.Str::plural('person', $live['count']).' on the site now' }}
            </p>

            <div class="gadya-dash__range" role="group" aria-label="Date range">
                @foreach ($this->getRangeOptions() as $option)
                    <button type="button" wire:click="setRange({{ $option }})" aria-pressed="{{ $this->days === $option ? 'true' : 'false' }}">{{ $option }} days</button>
                @endforeach
            </div>
        </div>

        <div class="gadya-dash__tiles">
            @foreach ([
                ['label' => 'Visits', 'value' => number_format($headline['views']), 'hint' => 'Pages opened'],
                ['label' => 'People', 'value' => number_format($headline['visitors']), 'hint' => 'Counted once a day each'],
                ['label' => 'Phone taps', 'value' => number_format($headline['phone_clicks']), 'hint' => 'Tapped your number'],
                ['label' => 'Enquiries', 'value' => number_format($headline['enquiries']), 'hint' => 'Started a booking or form'],
                ['label' => 'Got in touch', 'value' => $headline['contact_rate'].'%', 'hint' => 'Of everyone who visited'],
            ] as $tile)
                <div class="gadya-dash__tile">
                    <p class="gadya-dash__tile-label">{{ $tile['label'] }}</p>
                    <p class="gadya-dash__tile-value">{{ $tile['value'] }}</p>
                    <p class="gadya-dash__tile-hint">{{ $tile['hint'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- A bar per day, quiet days kept as gaps rather than closed up. --}}
        <div class="gadya-dash__card gadya-dash__card--flush">
            <div class="gadya-dash__head">
                <p class="gadya-dash__title">Visits per day</p>
                @if ($headline['views'] > 0)
                    <span>Busiest day: {{ number_format(collect($daily)->max('views')) }}</span>
                @endif
            </div>
            @if ($headline['views'] === 0)
                <p class="gadya-dash__empty gadya-dash__empty--tall">No visits in the last {{ $this->days }} days yet. The chart fills in as people find the site.</p>
            @else
                <div class="gadya-dash__body">
                    <div class="gadya-dash__chart" role="img" aria-label="Visits per day over the last {{ $this->days }} days">
                        @foreach ($daily as $day)
                            <div class="gadya-dash__day" title="{{ $day['day'] }}: {{ $day['views'] }} visits from {{ $day['visitors'] }} people">
                                <span style="height: {{ $day['views'] > 0 ? max(3, round($day['views'] / $peak * 100)) : 0 }}%"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="gadya-dash__axis">
                        <span>{{ $daily[0]['day'] ?? '' }}</span>
                        <span>{{ $daily[intdiv(count($daily), 2)]['day'] ?? '' }}</span>
                        <span>Today</span>
                    </div>
                </div>
            @endif
        </div>

        @if ($live['count'] > 0)
            <div class="gadya-dash__grid">
                <div class="gadya-dash__card gadya-dash__card--flush">
                    <div class="gadya-dash__head"><p class="gadya-dash__title">Looking at right now</p><span>People</span></div>
                    @foreach ($live['pages'] as $row)
                        <div class="gadya-dash__row"><span>{{ $name($row['path']) }}</span><span>{{ $row['visitors'] }}</span></div>
                    @endforeach
                </div>
                <div class="gadya-dash__card gadya-dash__card--flush">
                    <div class="gadya-dash__head"><p class="gadya-dash__title">Visiting from</p><span>People</span></div>
                    @forelse ($livePlaces as $row)
                        <div class="gadya-dash__row"><span>{{ $row['label'] }}</span><span>{{ $row['visitors'] }}</span></div>
                    @empty
                        <p class="gadya-dash__empty">Town and country show up on the live site, where the CDN tells us.</p>
                    @endforelse
                </div>
            </div>
        @endif

        @if ($countries !== [] && $this->worldMap)
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Where visitors come from</p></div>
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
                <div class="gadya-dash__body gadya-dash__map">{!! $this->worldMap !!}</div>
            </div>
        @endif

        <div class="gadya-dash__grid gadya-dash__grid--three">
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Most visited pages</p><span>Visits</span></div>
                @php $pagePeak = max(1, (int) $topPages->max('views')); @endphp
                @forelse ($topPages as $page)
                    <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($page->views, $pagePeak) }}"><span>{{ $name($page->path) }}</span><span>{{ number_format($page->views) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Nothing yet.</p>
                @endforelse
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">How they found you</p><span>People</span></div>
                @php $sourcePeak = max(1, (int) $referrers->max('visitors'), (int) $campaigns->max('visitors')); @endphp
                @forelse ($referrers as $referrer)
                    <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($referrer->visitors, $sourcePeak) }}"><span>{{ $referrer->referrer_host }}</span><span>{{ number_format($referrer->visitors) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Everyone so far came straight to the site.</p>
                @endforelse
                @if ($campaigns->isNotEmpty())
                    <p class="gadya-dash__subtitle">Campaigns</p>
                    @foreach ($campaigns as $campaign)
                        <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($campaign->visitors, $sourcePeak) }}"><span>{{ $campaign->utm_source }}{{ $campaign->utm_campaign ? ' · '.$campaign->utm_campaign : '' }}</span><span>{{ number_format($campaign->visitors) }}</span></div>
                    @endforeach
                @endif
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Countries</p><span>People</span></div>
                @php $countryPeak = max(1, ...array_values($countries ?: [0])); @endphp
                @forelse (collect($countries)->sortDesc()->take(8) as $code => $count)
                    <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($count, $countryPeak) }}"><span>{{ \Gadya\Cms\Analytics\VisitorGeo::flag(strtoupper($code)) }} {{ \Gadya\Cms\Analytics\VisitorGeo::countryName($code) }}</span><span>{{ number_format($count) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">Countries show up on the live site, where the CDN tells us.</p>
                @endforelse
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">What they did</p><span>Times</span></div>
                @forelse ($events as $event)
                    <div class="gadya-dash__row"><span>{{ Str::headline($event->name) }}</span><span>{{ number_format($event->total) }}</span></div>
                @empty
                    <p class="gadya-dash__empty">No phone taps, bookings or form sends yet.</p>
                @endforelse
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">On what</p></div>
                @if ($devices === [])
                    <p class="gadya-dash__empty">Nothing yet.</p>
                @else
                    <div class="gadya-dash__body">
                        <div class="gadya-dash__split" aria-hidden="true">
                            @foreach ($devices as $device)
                                <span style="flex: {{ max(1, $device['share']) }}"></span>
                            @endforeach
                        </div>
                        <ul class="gadya-dash__legend">
                            @foreach ($devices as $device)
                                <li><span class="gadya-dash__swatch"></span>{{ $device['label'] }}<strong>{{ $device['share'] }}%</strong></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Recently published</p></div>
                @forelse ($this->recentPublishes as $revision)
                    <div class="gadya-dash__row gadya-dash__row--stacked">
                        <span>{{ $revision->label ?: 'Changes published' }}</span>
                        <span>{{ $revision->publisher?->name ?? 'A team member' }} · {{ $revision->published_at?->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="gadya-dash__empty">Nothing published yet.</p>
                @endforelse
            </div>
        </div>

        @if (\Gadya\Cms\Filament\GadyaCmsPlugin::get()->hasSearch())
            @include('gadya-cms::filament.partials.dashboard-search')
        @endif

        <p class="gadya-dash__footnote">Counted here on your own site: no cookies, no Google, and no visitor's address is stored.</p>
    </div>
</x-filament-panels::page>
