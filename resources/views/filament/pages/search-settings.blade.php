@php
    $status = $this->searchStatus;
    $state = $status['status'] ?? null;
    $offered = $status !== null && $status['available'] === true;
    $timezone = app(\Gadya\Cms\Support\SiteTimezone::class);
    $upTo = $offered && in_array($state, ['connected', 'needs_reconnect'], true) ? $this->searchDataUpTo : null;
@endphp

<x-filament-panels::page>
    {{-- Connecting is one button. Only a paired site whose portal has Google
         set up is offered it; everyone else sees just the advanced form. --}}
    @if ($offered)
        <div class="gadya-dash"><div class="gadya-dash__card gadya-dash__card--flush gadya-gsc">
            <div class="gadya-dash__head">
                <p class="gadya-dash__title">Google Search Console</p>
                @if ($state === 'connected')
                    <span class="gadya-gsc__pill gadya-gsc__pill--ok">Connected</span>
                @elseif ($state === 'needs_reconnect')
                    <span class="gadya-gsc__pill gadya-gsc__pill--warn">Needs signing in again</span>
                @elseif ($state === 'no_property')
                    <span class="gadya-gsc__pill gadya-gsc__pill--warn">No property found</span>
                @endif
            </div>

            <div class="gadya-dash__body gadya-gsc__body">
                @if ($state === 'connected' || $state === 'needs_reconnect')
                    @if ($state === 'needs_reconnect')
                        <p class="gadya-gsc__warning" role="alert">Google stopped letting us read your search data, usually because access was removed or a password changed. Sign in again to carry on. The numbers already collected are kept.</p>
                    @endif

                    <p class="gadya-gsc__lead">
                        @if (($status['source'] ?? null) === 'agency')
                            Connected through Gadya Media
                        @elseif ($status['google_email'])
                            Connected as <strong>{{ $status['google_email'] }}</strong>
                        @else
                            Connected
                        @endif
                    </p>
                    <p class="gadya-dash__muted">
                        @if ($status['property'])
                            Property <strong>{{ $status['property'] }}</strong>
                        @endif
                        @if ($upTo)
                            &middot; Data up to {{ $upTo }}
                        @endif
                        @if ($status['last_synced_at'])
                            &middot; Last synced {{ $timezone->format($status['last_synced_at'], 'j M, g:ia') }}
                        @endif
                    </p>
                    @if ($status['last_error'])
                        <p class="gadya-dash__muted">Last problem: {{ $status['last_error'] }}</p>
                    @endif

                    <div class="gadya-gsc__actions">
                        @if ($state === 'needs_reconnect')
                            @if ($this->canConnectHere())
                                {{ $this->reconnectAction }}
                            @endif
                        @else
                            {{ $this->syncAction }}
                        @endif
                        {{ $this->disconnectAction }}
                    </div>
                @elseif ($state === 'no_property')
                    <p class="gadya-gsc__warning" role="alert">That Google account has no Search Console property for {{ $this->host }}. Add and verify the site at <a href="https://search.google.com/search-console" target="_blank" rel="noopener">search.google.com/search-console</a>, or sign in with another account.</p>
                    <div class="gadya-gsc__actions">
                        @if ($this->canConnectHere())
                            {{ $this->tryAnotherAction }}
                        @endif
                        {{ $this->disconnectAction }}
                    </div>
                @else
                    <p class="gadya-gsc__lead">See what people searched for to find this site, and which pages they landed on, right on your dashboard.</p>
                    <p class="gadya-dash__muted">Read-only. Gadya Media only reads search data, and you can disconnect any time. There is nothing to set up: you just sign in with Google.</p>

                    @if ($this->canConnectHere())
                        <div class="gadya-gsc__actions">{{ $this->connectAction }}</div>
                        <p class="gadya-dash__muted">Sign in with the Google account that owns or manages this site in Search Console.</p>
                    @else
                        <p class="gadya-gsc__warning" role="status">Google can only send people back to an https address, and this copy of the site is not on one. Open the admin on the live site to connect.</p>
                    @endif
                @endif
            </div>
        </div></div>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
