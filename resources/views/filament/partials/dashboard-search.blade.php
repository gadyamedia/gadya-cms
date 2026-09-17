@php
    $console = app(\Gadya\Cms\Search\SearchConsole::class);
    $pageSpeed = app(\Gadya\Cms\Search\PageSpeed::class);
    $readiness = app(\Gadya\Cms\Seo\AgentReadiness::class)->audit();
    $scores = $pageSpeed->latest();
    $grade = fn (?int $score): string => $score === null ? 'none' : ($score >= 90 ? 'good' : ($score >= 50 ? 'fair' : 'poor'));
    $failing = collect($readiness['checks'])->where('passed', false);
@endphp

<div class="gadya-dash__columns gadya-dash__columns--three" style="margin-top: 0">
    <div class="gadya-dash__card gadya-dash__card--flush">
        <p class="gadya-dash__title">In Google search</p>
        @if (! $console->isConfigured())
            <p class="gadya-dash__empty">Connect Search Console under <a href="{{ \Gadya\Cms\Filament\Pages\SearchSettings::getUrl() }}">Settings → Search &amp; speed</a> to see what people searched for.</p>
        @elseif ($console->topQueries()->isEmpty())
            <p class="gadya-dash__empty">Nothing fetched yet. Press <em>Fetch from Google now</em> under Search &amp; speed, or wait for the nightly fetch.</p>
        @else
            @php $totals = $console->totals(); @endphp
            <div class="gadya-dash__row"><span><strong>{{ number_format($totals['clicks']) }}</strong> clicks from <strong>{{ number_format($totals['impressions']) }}</strong> appearances</span><span class="gadya-dash__muted">last 28 days</span></div>
            @foreach ($console->topQueries(8) as $row)
                <div class="gadya-dash__row"><span>{{ $row->key }}</span><span>{{ $row->clicks }} <span class="gadya-dash__muted">· #{{ round($row->position) }}</span></span></div>
            @endforeach
        @endif
    </div>

    <div class="gadya-dash__card gadya-dash__card--flush">
        <p class="gadya-dash__title">Page speed</p>
        @if ($scores->isEmpty())
            <p class="gadya-dash__empty">No scores yet. Press <em>Check page speed now</em> under <a href="{{ \Gadya\Cms\Filament\Pages\SearchSettings::getUrl() }}">Search &amp; speed</a>.</p>
        @else
            @foreach ($scores->take(8) as $score)
                <div class="gadya-dash__row">
                    <span>{{ $score->path }}</span>
                    <span class="gadya-speed">
                        <span class="gadya-speed__pill gadya-speed__pill--{{ $grade($score->performance) }}" title="Performance">{{ $score->performance ?? '–' }}</span>
                        <span class="gadya-speed__pill gadya-speed__pill--{{ $grade($score->seo) }}" title="SEO">{{ $score->seo ?? '–' }}</span>
                        <span class="gadya-speed__pill gadya-speed__pill--{{ $grade($score->accessibility) }}" title="Accessibility">{{ $score->accessibility ?? '–' }}</span>
                    </span>
                </div>
            @endforeach
            <p class="gadya-dash__muted" style="padding: .5rem 1rem">Performance · SEO · Accessibility, on a phone. Checked {{ $scores->first()->checked_at->diffForHumans() }}.</p>
        @endif
    </div>

    <div class="gadya-dash__card gadya-dash__card--flush">
        <p class="gadya-dash__title">Ready for AI assistants</p>
        <div class="gadya-audit__head" style="padding: 1rem 1rem 0">
            <div class="gadya-audit__ring gadya-audit__ring--{{ $readiness['score'] >= 80 ? 'good' : ($readiness['score'] >= 50 ? 'fair' : 'poor') }}"><span>{{ $readiness['score'] }}</span></div>
            <div>
                <p class="gadya-audit__score">{{ $readiness['passed'] }} of {{ $readiness['total'] }} checks pass</p>
                <p class="gadya-dash__muted">robots, sitemap, llms.txt, structured data, Markdown for assistants</p>
            </div>
        </div>
        @forelse ($failing->take(4) as $check)
            <div class="gadya-dash__row"><span class="gadya-audit__tip">{{ $check['label'] }}</span><span class="gadya-dash__muted" style="text-align: right; max-width: 55%">{{ $check['fix'] }}</span></div>
        @empty
            <p class="gadya-dash__empty">Everything an assistant needs is in place.</p>
        @endforelse
    </div>
</div>
