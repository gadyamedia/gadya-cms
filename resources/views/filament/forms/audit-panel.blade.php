@php
    $audit = $getRecord()?->ai_meta['audit'] ?? null;
@endphp

@if (! $audit)
    <p class="gadya-dash__muted">Save the article to see how findable it is.</p>
@else
    @php
        $score = (int) $audit['score'];
        $grade = $score >= 80 ? 'good' : ($score >= 50 ? 'fair' : 'poor');
    @endphp
    <div class="gadya-audit">
        <div class="gadya-audit__head">
            <div class="gadya-audit__ring gadya-audit__ring--{{ $grade }}"><span>{{ $score }}</span></div>
            <div>
                <p class="gadya-audit__score">{{ $score }} out of 100</p>
                <p class="gadya-dash__muted">{{ $audit['passed'] }} of {{ $audit['total'] }} checks pass · checked again on every save</p>
            </div>
        </div>
        <ul class="gadya-audit__list">
            @foreach ($audit['checks'] as $check)
                <li class="gadya-audit__item gadya-audit__item--{{ $check['passed'] ? 'pass' : 'fail' }}">
                    <span class="gadya-audit__mark" aria-hidden="true">{{ $check['passed'] ? '✓' : '!' }}</span>
                    <span>
                        {{ $check['label'] }}
                        <span class="gadya-dash__muted">({{ $check['passed'] ? '+' : '' }}{{ $check['points'] }})</span>
                        @unless ($check['passed'])
                            — <span class="gadya-audit__tip">{{ $check['recommendation'] }}</span>
                        @endunless
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
