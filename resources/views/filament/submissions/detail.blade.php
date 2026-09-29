@php
    $labels = $submission->fieldLabels();
    $files = is_array($submission->files) ? $submission->files : [];
    $links = app(\Gadya\Cms\Forms\Builder\FormFiles::class);
@endphp
<dl class="gadya-submission">
    @foreach ($submission->data ?? [] as $field => $value)
        <div class="gadya-submission__row">
            <dt>{{ $labels[$field] ?? Str::headline((string) $field) }}</dt>
            <dd>
                @if (isset($files[$field]) && is_array($files[$field]))
                    @foreach ($files[$field] as $index => $file)
                        <a href="{{ $links->signedUrl($submission->getKey(), (string) $field, $index) }}" target="_blank" rel="noopener">{{ $file['name'] ?? 'File '.($index + 1) }}</a>@if (! $loop->last), @endif
                    @endforeach
                @elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL))
                    <a href="mailto:{{ $value }}">{{ $value }}</a>
                @elseif (is_array($value))
                    {{ \Gadya\Cms\Forms\MergeTags::text($value) }}
                @else
                    {!! nl2br(e((string) $value)) !!}
                @endif
            </dd>
        </div>
    @endforeach
    @foreach ((array) (($submission->meta ?? [])['consents'] ?? []) as $field => $consent)
        <div class="gadya-submission__row gadya-submission__row--meta">
            <dt>Agreed to</dt>
            <dd>"{{ $consent['text'] ?? '' }}" · {{ isset($consent['at']) ? \Illuminate\Support\Carbon::parse($consent['at'])->format('D j M Y, g:ia') : '' }}</dd>
        </div>
    @endforeach
    @if ($submission->answered_at)
        <div class="gadya-submission__row gadya-submission__row--meta">
            <dt>Answered</dt>
            <dd>{{ $submission->answered_at->format('D j M Y') }} · {{ $submission->created_at->diffForHumans($submission->answered_at, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }} after it arrived</dd>
        </div>
    @endif
    @if ($submission->follow_up_at)
        <div class="gadya-submission__row gadya-submission__row--meta">
            <dt>Follow up</dt>
            <dd>{{ $submission->follow_up_at->format('D j M Y') }}</dd>
        </div>
    @endif
    @if (filled($submission->notes))
        <div class="gadya-submission__row gadya-submission__row--meta">
            <dt>Notes</dt>
            <dd>{!! nl2br(e($submission->notes)) !!}</dd>
        </div>
    @endif
    <div class="gadya-submission__row gadya-submission__row--meta">
        <dt>Sent</dt>
        <dd>{{ $submission->created_at->format('D j M Y, g:ia') }} from {{ $submission->path ?: 'the site' }}@if ($submission->country) · {{ \Gadya\Cms\Analytics\VisitorGeo::flag($submission->country) }} {{ $submission->country }}@endif</dd>
    </div>
</dl>
