<dl class="gadya-submission">
    @foreach ($submission->data ?? [] as $field => $value)
        <div class="gadya-submission__row">
            <dt>{{ Str::headline((string) $field) }}</dt>
            <dd>
                @if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL))
                    <a href="mailto:{{ $value }}">{{ $value }}</a>
                @elseif (is_array($value))
                    {{ implode(', ', $value) }}
                @else
                    {!! nl2br(e((string) $value)) !!}
                @endif
            </dd>
        </div>
    @endforeach
    <div class="gadya-submission__row gadya-submission__row--meta">
        <dt>Sent</dt>
        <dd>{{ $submission->created_at->format('D j M Y, g:ia') }} from {{ $submission->path ?: 'the site' }}@if ($submission->country) · {{ \Gadya\Cms\Analytics\VisitorGeo::flag($submission->country) }} {{ $submission->country }}@endif</dd>
    </div>
</dl>
