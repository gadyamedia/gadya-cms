@php
    /*
     * Google Search Console through the Gadya Media portal. Every figure is
     * read from a fifteen-minute cache, so the dashboard's polling never
     * reaches the portal. A site that is not paired, or whose portal has no
     * Google set up, shows nothing here: the older card below still covers
     * a client's own service account.
     */
    $searchStatus = $this->searchStatus;
    $searchState = $searchStatus['status'] ?? null;
    $searchOffered = $searchStatus !== null && $searchStatus['available'] === true;
    $p = $this->searchPerformance;
    $timezone = app(\Gadya\Cms\Support\SiteTimezone::class);
    $settingsUrl = \Gadya\Cms\Filament\Pages\SearchSettings::getUrl();
    $canManage = auth()->user()?->can(\Gadya\Cms\Access\Abilities::gate(\Gadya\Cms\Access\Abilities::SETTINGS)) ?? false;
    $n = fn (int|float $value): string => number_format($value);
    $pct = fn (float $fraction): string => number_format($fraction * 100, 1).'%';
    $day = fn (string $date): string => \Illuminate\Support\Carbon::parse($date)->format('j M');
    $path = fn (string $url): string => (parse_url($url, PHP_URL_PATH) ?: '/').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');
@endphp

@if ($searchOffered)
    <div class="gadya-dash__card gadya-dash__card--flush gadya-gsc-section">
        <div class="gadya-dash__head">
            <p class="gadya-dash__title">Search</p>
            @if ($p !== null)
                <div class="gadya-dash__range" role="group" aria-label="Search date range">
                    @foreach ($this->getSearchRangeOptions() as $option)
                        <button type="button" wire:click="setSearchRange({{ $option }})" aria-pressed="{{ $this->searchDays === $option ? 'true' : 'false' }}">{{ $option }} days</button>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($p === null && $searchState === 'connected')
            <p class="gadya-dash__empty">Google Search Console is connected, but the numbers could not be fetched just now. They will show up here shortly.</p>
        @elseif ($p === null)
            {{-- Not connected yet: an invitation, not an error. --}}
            <div class="gadya-dash__body gadya-gsc__body">
                <p class="gadya-gsc__lead">See what people searched for to find you.</p>
                <p class="gadya-dash__muted">
                    @if ($searchState === 'no_property')
                        The Google account you signed in with has no Search Console property for this site. Try another account.
                    @else
                        Connect Google Search Console and your searches, clicks and top pages show up here. Read-only, nothing to set up, and you can disconnect any time.
                    @endif
                </p>
                @if ($canManage)
                    <div class="gadya-gsc__actions"><a class="gadya-gsc__button" href="{{ $settingsUrl }}">{{ $searchState === 'no_property' ? 'Try another account' : 'Connect Google Search Console' }}</a></div>
                @endif
            </div>
        @else
            @if ($searchState === 'needs_reconnect')
                <p class="gadya-gsc__warning gadya-gsc__warning--flush" role="alert">Google stopped letting us read your search data, so these numbers are the last we collected. @if ($canManage)<a href="{{ $settingsUrl }}">Sign in again</a> to bring them up to date.@endif</p>
            @endif

            @if ($p['synced_at'] === null)
                <p class="gadya-dash__empty">Connected. The first numbers are on their way and usually arrive within a few minutes.</p>
            @else
                @php
                    /* A move up is good for clicks, appearances and rate; for position a smaller number is. */
                    $delta = function (?float $change, bool $lowerIsBetter = false, string $unit = '%'): array {
                        if ($change === null) {
                            return ['text' => 'Nothing earlier to compare', 'tone' => 'none', 'compare' => false];
                        }

                        if ($change == 0.0) {
                            return ['text' => 'No change', 'tone' => 'none', 'compare' => true];
                        }

                        $better = $lowerIsBetter ? $change < 0 : $change > 0;
                        $amount = number_format(abs($change), 1);
                        $wording = $unit === '%' ? ($change > 0 ? "Up {$amount}%" : "Down {$amount}%") : ($change < 0 ? "{$amount} places higher" : "{$amount} places lower");

                        return ['text' => ($better ? '▲ ' : '▼ ').$wording, 'tone' => $better ? 'good' : 'poor', 'compare' => true];
                    };
                    $tiles = [
                        ['label' => 'Clicks', 'value' => $n($p['totals']['clicks']), 'hint' => 'From Google to your site', 'delta' => $delta($p['changes']['clicks'])],
                        ['label' => 'Appearances', 'value' => $n($p['totals']['impressions']), 'hint' => 'Times you showed in results', 'delta' => $delta($p['changes']['impressions'])],
                        ['label' => 'Average position', 'value' => $p['totals']['position'] > 0 ? number_format($p['totals']['position'], 1) : '–', 'hint' => 'Where you rank, 1 is the top', 'delta' => $delta($p['changes']['position'], lowerIsBetter: true, unit: 'places')],
                        ['label' => 'Click-through rate', 'value' => $pct($p['totals']['ctr']), 'hint' => 'Of appearances that got a click', 'delta' => $delta($p['changes']['ctr'])],
                    ];
                    $peakClicks = max(1, collect($p['daily'])->max('clicks'));
                    $peakSeen = max(1, collect($p['daily'])->max('impressions'));
                    $deviceTotal = max(1, collect($p['devices'])->sum('clicks'));
                    $share = fn (int|float $value, int|float $of): string => round($of > 0 ? $value / $of * 100 : 0, 1).'%';
                @endphp

                <div class="gadya-dash__tiles gadya-dash__tiles--four">
                    @foreach ($tiles as $tile)
                        <div class="gadya-dash__tile">
                            <p class="gadya-dash__tile-label">{{ $tile['label'] }}</p>
                            <p class="gadya-dash__tile-value">{{ $tile['value'] }}</p>
                            <p class="gadya-gsc__delta gadya-gsc__delta--{{ $tile['delta']['tone'] }}">{{ $tile['delta']['text'] }}@if ($tile['delta']['compare']) <span class="gadya-dash__muted">vs the {{ $p['days'] }} days before</span>@endif</p>
                            <p class="gadya-dash__tile-hint">{{ $tile['hint'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="gadya-dash__grid">
                    @foreach ([
                        ['title' => 'Clicks per day', 'key' => 'clicks', 'peak' => $peakClicks, 'noun' => 'clicks'],
                        ['title' => 'Appearances per day', 'key' => 'impressions', 'peak' => $peakSeen, 'noun' => 'appearances'],
                    ] as $chart)
                        <div class="gadya-gsc__chartbox">
                            <p class="gadya-dash__subtitle gadya-gsc__charttitle">{{ $chart['title'] }}</p>
                            <div class="gadya-dash__body">
                                <div class="gadya-dash__chart" role="img" aria-label="{{ $chart['title'] }} over the last {{ $p['days'] }} days: {{ $n(collect($p['daily'])->sum($chart['key'])) }} in all, busiest day {{ $n(collect($p['daily'])->max($chart['key'])) }}">
                                    @foreach ($p['daily'] as $row)
                                        <div class="gadya-dash__day" title="{{ $day($row['date']) }}: {{ $n($row[$chart['key']]) }} {{ $chart['noun'] }}">
                                            <span style="height: {{ $row[$chart['key']] > 0 ? max(3, round($row[$chart['key']] / $chart['peak'] * 100)) : 0 }}%"></span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="gadya-dash__axis">
                                    <span>{{ isset($p['daily'][0]) ? $day($p['daily'][0]['date']) : '' }}</span>
                                    <span>{{ $p['daily'] !== [] ? $day($p['daily'][intdiv(count($p['daily']), 2)]['date']) : '' }}</span>
                                    <span>{{ isset($p['daily'][0]) ? $day($p['daily'][count($p['daily']) - 1]['date']) : '' }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="gadya-dash__grid">
                    <div class="gadya-gsc__chartbox">
                        <div class="gadya-dash__head"><p class="gadya-dash__title">Top searches</p><span>Clicks</span></div>
                        @forelse (array_slice($p['queries'], 0, 10) as $row)
                            <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($row['clicks'], max(1, $p['queries'][0]['clicks'])) }}"><span>{{ $row['query'] }}</span><span>{{ $n($row['clicks']) }} <small class="gadya-dash__muted">{{ $n($row['impressions']) }} seen · #{{ number_format($row['position'], 1) }}</small></span></div>
                        @empty
                            <p class="gadya-dash__empty">No searches yet for this range.</p>
                        @endforelse
                    </div>
                    <div class="gadya-gsc__chartbox">
                        <div class="gadya-dash__head"><p class="gadya-dash__title">Top pages</p><span>Clicks</span></div>
                        @forelse (array_slice($p['pages'], 0, 10) as $row)
                            <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($row['clicks'], max(1, $p['pages'][0]['clicks'])) }}"><span title="{{ $row['page'] }}">{{ $path($row['page']) }}</span><span>{{ $n($row['clicks']) }} <small class="gadya-dash__muted">{{ $n($row['impressions']) }} seen · #{{ number_format($row['position'], 1) }}</small></span></div>
                        @empty
                            <p class="gadya-dash__empty">No pages yet for this range.</p>
                        @endforelse
                    </div>
                </div>

                <div class="gadya-dash__grid">
                    <div class="gadya-gsc__chartbox">
                        <div class="gadya-dash__head"><p class="gadya-dash__title">Countries</p><span>Clicks</span></div>
                        @php $countryPeak = max(1, collect($p['countries'])->max('clicks')); @endphp
                        @forelse ($p['countries'] as $row)
                            <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($row['clicks'], $countryPeak) }}"><span>{{ $row['name'] }}</span><span>{{ $n($row['clicks']) }} <small class="gadya-dash__muted">{{ $n($row['impressions']) }} seen</small></span></div>
                        @empty
                            <p class="gadya-dash__empty">Nothing yet.</p>
                        @endforelse
                    </div>
                    <div class="gadya-gsc__chartbox">
                        <div class="gadya-dash__head"><p class="gadya-dash__title">On what</p><span>Clicks</span></div>
                        @forelse ($p['devices'] as $row)
                            <div class="gadya-dash__row gadya-dash__row--share" style="--share: {{ $share($row['clicks'], $deviceTotal) }}"><span>{{ $row['label'] }}</span><span>{{ $n($row['clicks']) }} <small class="gadya-dash__muted">{{ $share($row['clicks'], $deviceTotal) }}</small></span></div>
                        @empty
                            <p class="gadya-dash__empty">Nothing yet.</p>
                        @endforelse
                    </div>
                </div>

                <p class="gadya-dash__note">
                    Data up to {{ \Illuminate\Support\Carbon::parse($p['to'])->format('j F Y') }}; Google reports a few days late.
                    Updated {{ $timezone->format($p['synced_at'], 'j M, g:ia') }}.
                </p>
            @endif
        @endif
    </div>
@endif
