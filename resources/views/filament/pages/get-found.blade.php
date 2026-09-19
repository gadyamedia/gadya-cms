@php
    $labels = ['ok' => 'In place', 'missing' => 'Add this', 'wrong' => 'Points elsewhere', 'optional' => 'Optional'];
    $setup = app(\Gadya\Cms\Seo\SiteSetup::class);
    $primary = $setup->primaryDomain();
    $signals = collect((array) config('gadya-cms.seo.content_signals', []))->map(fn ($value, $key) => $key.'='.$value)->implode(', ');
    $allowed = count((array) config('gadya-cms.seo.ai_crawlers.allow', []));
    $blocked = count((array) config('gadya-cms.seo.ai_crawlers.block', []));
@endphp

<x-filament-panels::page>
    <div class="gadya-dash">
        {{-- The files a crawler asks for first, fetched the way it would. --}}
        <div class="gadya-dash__card gadya-dash__card--flush">
            <div class="gadya-dash__head"><p class="gadya-dash__title">Can Google and AI assistants reach the site?</p><span>Checked live</span></div>
            @foreach ($this->liveChecks as $check)
                <div class="gadya-setup__check">
                    <span @class(['gadya-setup__mark', 'gadya-setup__mark--ok' => $check['ok']])>{{ $check['ok'] ? '✓' : '!' }}</span>
                    <div>
                        <p><a href="{{ $check['url'] }}" target="_blank" rel="noopener">{{ $check['path'] }}</a> <span class="gadya-dash__muted">{{ $check['ok'] ? 'answers' : ($check['status'] === 0 ? 'did not answer' : 'answers '.$check['status']) }}</span></p>
                        @unless ($check['ok'])
                            <p class="gadya-setup__fix">{{ $check['fix'] }}</p>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>

        <div class="gadya-dash__grid">
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Add your sitemap to Google</p></div>
                <div class="gadya-dash__body">
                    <p class="gadya-dash__muted">The sitemap lists every published page and article, and updates itself whenever you publish.</p>
                    <div class="gadya-preview__row" x-data="{ copied: false }">
                        <input class="gadya-preview__input" type="text" readonly value="{{ $this->sitemapUrl }}" x-ref="url" x-on:focus="$el.select()">
                        <button type="button" class="gadya-preview__copy" x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                    </div>
                    <ol class="gadya-setup__steps">
                        <li>Open <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> and sign in with the Google account that should own the site.</li>
                        <li>Choose <strong>Add property</strong>, then <strong>Domain</strong>, type <strong>{{ $primary }}</strong> and press Continue.</li>
                        <li>Google shows a TXT record starting <code>google-site-verification=</code>. Add it at your DNS host (the TXT row below), then press <strong>Verify</strong>. It can take up to an hour to be seen.</li>
                        <li>Open <strong>Sitemaps</strong> in the left menu, paste <strong>{{ $this->sitemapUrl }}</strong> under <em>Add a new sitemap</em> and press <strong>Submit</strong>. The status should read <em>Success</em>.</li>
                        <li>Optional: open <strong>URL inspection</strong>, paste your home page address and press <strong>Request indexing</strong>.</li>
                        @if (\Gadya\Cms\Filament\GadyaCmsPlugin::get()->hasSearch())
                            <li>Connect Search Console under <a href="{{ \Gadya\Cms\Filament\Pages\SearchSettings::getUrl() }}">Search &amp; speed</a> to see what people search for on the dashboard.</li>
                        @endif
                    </ol>
                </div>
            </div>

            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head"><p class="gadya-dash__title">Bing, Copilot and DuckDuckGo</p></div>
                <div class="gadya-dash__body">
                    <p class="gadya-dash__muted">Bing's index feeds Microsoft Copilot and DuckDuckGo too, so it is worth the two minutes once Google is done.</p>
                    <ol class="gadya-setup__steps">
                        <li>Open <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a> and sign in.</li>
                        <li>Choose <strong>Import from Google Search Console</strong>. It brings the site and the sitemap across, already verified.</li>
                        <li>No Google yet? Choose <strong>Add your site manually</strong>, verify with the DNS record it offers, then submit the same sitemap address under <strong>Sitemaps</strong>.</li>
                    </ol>
                </div>
            </div>
        </div>

        @foreach ($this->domainRecords as $domain => $records)
            @php $host = $setup->dnsHost($domain); @endphp
            <div class="gadya-dash__card gadya-dash__card--flush">
                <div class="gadya-dash__head">
                    <p class="gadya-dash__title">DNS for {{ $domain }}</p>
                    <span>{{ $domain === $primary ? 'Main site' : 'Redirects to '.$primary }}{{ $host ? ' · DNS at '.$host : '' }}</span>
                </div>
                @unless ($setup->isPublic($domain))
                    <p class="gadya-dash__empty">{{ $domain }} is a local address, so there is nothing to look up. List the real domains under <code>seo.domains</code> in config/gadya-cms.php to check them from here.</p>
                    </div>
                    @continue
                @endunless
                @if ($domain !== $primary)
                    <p class="gadya-dash__lede">Point it at the same server, then add <strong>{{ $domain }}</strong> and <strong>www.{{ $domain }}</strong> as aliases of the site on the server (Laravel Forge: the site's <em>Domains</em> tab) with a redirect to https://{{ $primary }}. Turn off any parking or forwarding page at the registrar.</p>
                @endif
                <div class="gadya-setup__table-wrap">
                    <table class="gadya-setup__table">
                        <thead>
                            <tr><th>Type</th><th>Name</th><th>Value</th><th>Now</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($records as $record)
                                <tr>
                                    <td><code>{{ $record['type'] }}</code></td>
                                    <td><code>{{ $record['name'] }}</code></td>
                                    <td>
                                        <code class="gadya-setup__value">{{ $record['value'] }}</code>
                                        <p class="gadya-dash__muted">{{ $record['why'] }}</p>
                                        @if ($record['status'] === 'wrong' && $record['found'] !== [])
                                            <p class="gadya-setup__fix">Found {{ implode(', ', $record['found']) }} instead.</p>
                                        @endif
                                    </td>
                                    <td><span class="gadya-setup__pill gadya-setup__pill--{{ $record['status'] }}">{{ $labels[$record['status']] ?? $record['status'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="gadya-dash__note"><code>@</code> means the domain itself; some DNS hosts want that box left empty instead. Changes can take a few minutes to a day to show here.</p>
            </div>
        @endforeach

        <div class="gadya-dash__card gadya-dash__card--flush">
            <div class="gadya-dash__head"><p class="gadya-dash__title">Ready for AI assistants</p><span>Already done for you</span></div>
            <ul class="gadya-setup__list">
                <li><strong>robots.txt</strong> names {{ $allowed }} AI {{ Str::plural('crawler', $allowed) }} it welcomes{{ $blocked ? ' and '.$blocked.' it turns away' : '' }}, and points to the sitemap.</li>
                @if ($signals !== '')
                    <li><strong>Content signals</strong> in robots.txt say how the site may be used: <code>{{ $signals }}</code>.</li>
                @endif
                @if (config('gadya-cms.seo.llms', true))
                    <li><a href="{{ url('/llms.txt') }}" target="_blank" rel="noopener"><strong>llms.txt</strong></a> describes the site and its pages in plain Markdown for assistants.</li>
                @endif
                @if (config('gadya-cms.seo.markdown', true))
                    <li>Every page and article can be read as <strong>Markdown</strong> by an assistant that asks for it.</li>
                @endif
                @if (config('gadya-cms.seo.link_headers', true))
                    <li>The home page's <strong>Link headers</strong> point agents at the sitemap and llms.txt.</li>
                @endif
            </ul>
            <p class="gadya-dash__note">Checkers such as <a href="https://isitagentready.com/?url={{ urlencode('https://'.$primary) }}" target="_blank" rel="noopener">isitagentready.com</a> also look for agent DNS records (DNS-AID), MCP server cards, OAuth and payments. Those only apply to a site that runs its own agent or API, so a business site rightly leaves them out.</p>
        </div>
    </div>
</x-filament-panels::page>
