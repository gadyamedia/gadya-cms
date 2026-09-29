@php($forms = app(\Gadya\Cms\Analytics\FormAnalytics::class)->overview($this->days))
@if ($forms !== [])
    <div class="gadya-dash__card gadya-dash__card--flush">
        <div class="gadya-dash__head"><p class="gadya-dash__title">Forms</p><span>Saw · sent · conversion</span></div>
        @foreach ($forms as $row)
            <div class="gadya-dash__row">
                <span><a href="{{ \Gadya\Cms\Filament\Resources\Forms\FormResource::getUrl('stats', ['record' => $row['form']]) }}">{{ $row['form']->title }}</a></span>
                <span>{{ number_format($row['views']) }} · {{ number_format($row['completions']) }} · {{ $row['conversion'] }}%</span>
            </div>
        @endforeach
    </div>
@endif
