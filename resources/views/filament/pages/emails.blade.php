<x-filament-panels::page>
    {{ $this->form }}

    @php($recent = $this->recentEmails())

    @if ($recent !== [])
        <div class="gadya-dash__card gadya-dash__card--flush">
            <div class="gadya-dash__head">
                <p class="gadya-dash__title">Email sent lately</p>
                <span>As Gadya Media, who sends it for you, has it</span>
            </div>

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
                                <td>{{ $email['subject'] }}</td>
                                <td>
                                    <span class="gadya-setup__pill gadya-setup__pill--{{ ($email['failed'] ?? false) ? 'wrong' : 'ok' }}">
                                        {{ ($email['failed'] ?? false) ? 'Did not send' : 'Sent' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="gadya-dash__note">Anything that did not send is with Gadya Media, who will already have seen it. Email <a href="mailto:help@support.gadya.media">help@support.gadya.media</a> if something you expected is missing.</p>
        </div>
    @endif
</x-filament-panels::page>
