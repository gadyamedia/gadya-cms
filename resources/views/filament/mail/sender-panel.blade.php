@php
    $limit ??= 5;
    $recent = array_slice($panel['recent'], 0, $limit);
@endphp

<div class="gadya-sender">
    <p class="gadya-sender__title">Who is this sent by?</p>

    <dl class="gadya-sender__list">
        <div>
            <dt>From</dt>
            <dd>{{ $panel['from'] }}<br><span class="gadya-dash__muted">{{ $panel['transport'] }}</span></dd>
        </div>
        <div>
            <dt>Replies go to</dt>
            <dd>
                {{ $panel['visitor_replies'] }}<br>
                <span class="gadya-dash__muted">The reply to a visitor goes to {{ $panel['reply_to'] }}.</span>
            </dd>
        </div>
        @if ($panel['allowance'])
            <div>
                <dt>Sending allowance</dt>
                <dd>{{ $panel['allowance'] }}</dd>
            </div>
        @endif
    </dl>

    @foreach ($panel['warnings'] as $warning)
        <div class="gadya-sender__warning" role="alert">
            <strong>{{ $warning['problem'] }}</strong>
            <span>{{ $warning['fix'] }}</span>
        </div>
    @endforeach

    @if ($recent !== [])
        <p class="gadya-sender__subtitle">Sent lately</p>
        <div class="gadya-setup__table-wrap">
            <table class="gadya-setup__table">
                <thead>
                    <tr><th>When</th><th>To</th><th>Subject</th><th>Sent</th></tr>
                </thead>
                <tbody>
                    @foreach ($recent as $email)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($email['sent_at'])->timezone(config('app.timezone'))->format('j M, g:ia') }}</td>
                            <td>{{ $email['to'] }}@if (($email['recipients'] ?? 1) > 1) <span class="gadya-dash__muted">and {{ $email['recipients'] - 1 }} more</span>@endif</td>
                            <td>{{ $email['subject'] }}@if (($email['purpose'] ?? null) === 'test') <span class="gadya-dash__muted">(a test)</span>@endif</td>
                            <td>
                                <span class="gadya-setup__pill gadya-setup__pill--{{ ($email['failed'] ?? false) ? 'wrong' : 'ok' }}">
                                    {{ ($email['failed'] ?? false) ? 'Did not send' : 'Sent' }}
                                </span>
                                @if (filled($email['reason'] ?? null))
                                    <br><span class="gadya-dash__muted">{{ $email['reason'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="gadya-dash__note">As Gadya Media, who sends it for you, has it. Email <a href="mailto:help@support.gadya.media">help@support.gadya.media</a> if something you expected is missing.</p>
    @elseif ($panel['recent_note'])
        <p class="gadya-dash__note">{{ $panel['recent_note'] }}</p>
    @endif
</div>
