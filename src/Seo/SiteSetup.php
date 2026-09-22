<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Mail\SharedSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * What a site needs outside the code to be found: the addresses search
 * engines and assistants fetch, and the DNS records each domain should
 * carry. Everything is checked for real, so the page says "done" only
 * when it is.
 */
class SiteSetup
{
    private const CACHE_KEY = 'gadya-cms.site-setup.checks';

    public function __construct(private readonly DnsLookup $dns) {}

    /**
     * The domains the business owns, the one the site lives on first.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $configured = array_map(
            fn ($domain): string => Str::of((string) $domain)->lower()->after('://')->before('/')->replaceStart('www.', '')->toString(),
            (array) config('gadya-cms.seo.domains', []),
        );

        $domains = array_values(array_unique(array_filter($configured)));

        return $domains !== [] ? $domains : [$this->appHost()];
    }

    public function primaryDomain(): string
    {
        return $this->domains()[0];
    }

    public function sitemapUrl(): string
    {
        return $this->isPublic($this->primaryDomain()) ? 'https://'.$this->primaryDomain().'/sitemap.xml' : url('/sitemap.xml');
    }

    /**
     * The records a domain should carry, each with what is there now.
     *
     * @return list<array{type: string, name: string, value: string, why: string, status: string, found: list<string>}>
     */
    public function records(string $domain): array
    {
        $primary = $domain === $this->primaryDomain();
        $server = $this->serverAddress();
        $address = $this->values($domain, 'A');
        $www = array_merge($this->values('www.'.$domain, 'CNAME'), $this->values('www.'.$domain, 'A'));
        $txt = $this->values($domain, 'TXT');

        $records = [
            [
                'type' => 'A',
                'name' => '@',
                'value' => $server ?? 'Your server\'s IP address',
                'why' => $primary ? 'Sends visitors to the server the site runs on.' : 'Sends this domain to the same server, which then redirects it to '.$this->primaryDomain().'.',
                'status' => $address === [] ? 'missing' : ($server !== null && ! in_array($server, $address, true) ? 'wrong' : 'ok'),
                'found' => $address,
            ],
            [
                'type' => 'CNAME',
                'name' => 'www',
                'value' => $domain,
                'why' => 'So www.'.$domain.' works too; the server redirects it to '.$this->primaryDomain().'.',
                'status' => $www === [] ? 'missing' : ($server !== null && ! in_array(rtrim($domain, '.'), array_map(fn (string $value): string => rtrim($value, '.'), $www), true) && ! in_array($server, $www, true) ? 'wrong' : 'ok'),
                'found' => $www,
            ],
        ];

        if ($primary) {
            $verified = array_values(array_filter($txt, fn (string $value): bool => str_starts_with($value, 'google-site-verification=')));

            $records[] = [
                'type' => 'TXT',
                'name' => '@',
                'value' => 'google-site-verification=… (Search Console gives you this)',
                'why' => 'Proves to Google you own the domain, so Search Console shows every address on it.',
                'status' => $verified === [] ? 'missing' : 'ok',
                'found' => $verified,
            ];

            $shared = app(SharedSender::class);

            if (! $this->sendsMailFrom($domain) && $shared->enabled()) {
                /*
                 * The site's email is sent by Gadya Media from its own
                 * domain, so SPF here would say nothing. DMARC still
                 * belongs on the client's domain: it is what stops someone
                 * else sending as her, and Gmail and Yahoo look for it.
                 */
                $dmarc = array_values(array_filter($this->values('_dmarc.'.$domain, 'TXT'), fn (string $value): bool => str_starts_with($value, 'v=DMARC1')));

                $records[] = [
                    'type' => 'TXT',
                    'name' => '_dmarc',
                    'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domain,
                    'why' => 'Your email is sent by Gadya Media from '.$shared->address().', so this domain needs no SPF record of its own. DMARC still belongs here: it stops anyone else sending as '.$domain.'.',
                    'status' => $dmarc === [] ? 'missing' : 'ok',
                    'found' => $dmarc,
                ];
            }

            if ($this->sendsMailFrom($domain)) {
                $spf = array_values(array_filter($txt, fn (string $value): bool => str_starts_with($value, 'v=spf1')));
                $dmarc = array_values(array_filter($this->values('_dmarc.'.$domain, 'TXT'), fn (string $value): bool => str_starts_with($value, 'v=DMARC1')));

                $records[] = [
                    'type' => 'TXT',
                    'name' => '@',
                    'value' => 'v=spf1 '.$this->spfInclude().' ~all',
                    'why' => 'Lets the site\'s emails (enquiry alerts, auto-replies) land in inboxes rather than spam.',
                    'status' => $spf === [] ? 'missing' : 'ok',
                    'found' => $spf,
                ];

                $records[] = [
                    'type' => 'TXT',
                    'name' => '_dmarc',
                    'value' => 'v=DMARC1; p=none; rua=mailto:dmarc@'.$domain,
                    'why' => 'Tells inboxes what to do with mail that fails the check above. Gmail and Yahoo expect it.',
                    'status' => $dmarc === [] ? 'missing' : 'ok',
                    'found' => $dmarc,
                ];
            }

            /*
             * DNS for AI Discovery (draft-mozleywilliams-dnsop-dnsaid):
             * an agent that knows only the domain can find the site's
             * discovery documents without fetching a page first. Optional,
             * and a draft - but the record costs nothing and the registrar
             * is the only place it can be added.
             */
            $dnsaid = $this->values('_index._agents.'.$domain, 'HTTPS');

            $records[] = [
                'type' => 'HTTPS',
                'name' => '_index._agents',
                'value' => '1 . alpn="h2" endpoint="/.well-known/ai-catalog.json"',
                'why' => 'Optional and still a draft standard. It lets an AI agent that knows only the domain find what the site offers, without loading a page first. Add it as an HTTPS (SVCB) record at your registrar; sign the zone with DNSSEC if it offers that.',
                'status' => $dnsaid === [] ? 'optional' : 'ok',
                'found' => $dnsaid,
            ];

            $caa = $this->values($domain, 'CAA');

            $records[] = [
                'type' => 'CAA',
                'name' => '@',
                'value' => '0 issue "letsencrypt.org"',
                'why' => 'Optional. Only your certificate issuer may create HTTPS certificates for the domain.',
                'status' => $caa === [] ? 'optional' : 'ok',
                'found' => $caa,
            ];
        } else {
            $spf = array_values(array_filter($txt, fn (string $value): bool => str_starts_with($value, 'v=spf1')));

            $records[] = [
                'type' => 'TXT',
                'name' => '@',
                'value' => 'v=spf1 -all',
                'why' => 'Optional, when no email is sent from this domain: stops anyone sending mail pretending to be it.',
                'status' => $spf === [] ? 'optional' : 'ok',
                'found' => $spf,
            ];
        }

        return $records;
    }

    /**
     * Who answers DNS for a domain, named when it is a host people know,
     * so the page can say where the records go.
     */
    public function dnsHost(string $domain): ?string
    {
        $servers = implode(' ', array_map('strtolower', $this->values($domain, 'NS')));

        if ($servers === '') {
            return null;
        }

        foreach ([
            'afternic.com' => 'Afternic, a domain-for-sale parking service: check the business owns this domain',
            'sedoparking.com' => 'Sedo parking, a domain-for-sale service: check the business owns this domain',
            'domaincontrol.com' => 'GoDaddy',
            'cloudflare.com' => 'Cloudflare',
            'awsdns' => 'Amazon Route 53',
            'digitalocean.com' => 'DigitalOcean',
            'registrar-servers.com' => 'Namecheap',
            'googledomains.com' => 'Squarespace (was Google Domains)',
            'squarespacedns.com' => 'Squarespace',
            'hetzner' => 'Hetzner',
            'linode.com' => 'Akamai (Linode)',
            'vultr.com' => 'Vultr',
            'name-services.com' => 'Enom',
            'hostinger' => 'Hostinger',
            'wixdns.net' => 'Wix',
            'porkbun.com' => 'Porkbun',
            'dnsimple.com' => 'DNSimple',
            'ionos' => 'IONOS',
            'ovh.net' => 'OVHcloud',
        ] as $needle => $name) {
            if (str_contains($servers, $needle)) {
                return $name;
            }
        }

        return $this->values($domain, 'NS')[0];
    }

    /**
     * Fetches the files search engines and assistants ask for first, the
     * way they would, and says what to do about any that do not answer.
     *
     * @return list<array{path: string, url: string, status: int, ok: bool, fix: string}>
     */
    public function liveChecks(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
            $checks = [];

            foreach ($this->livePaths() as $path => $fix) {
                $url = url($path);

                /*
                 * `php -S` answers one request at a time, so asking itself
                 * for a file would wait on its own reply until it times out.
                 */
                if (PHP_SAPI === 'cli-server') {
                    $checks[] = ['path' => $path, 'url' => $url, 'status' => 0, 'ok' => false, 'fix' => 'Not checked: the built-in PHP server cannot fetch its own pages. It is checked on the real site.'];

                    continue;
                }

                $status = rescue(fn (): int => Http::timeout(8)->withoutRedirecting()->get($url)->status(), 0, report: false);

                $checks[] = ['path' => $path, 'url' => $url, 'status' => $status, 'ok' => $status === 200, 'fix' => $fix];
            }

            return $checks;
        });
    }

    public function forgetLiveChecks(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->dns->forget();
    }

    /** Whether the domain can be looked up at all, which a .test site cannot. */
    public function isPublic(string $domain): bool
    {
        return $this->dns->isPublic($domain);
    }

    /**
     * @return array<string, string>
     */
    private function livePaths(): array
    {
        $paths = [];

        if (config('gadya-cms.seo.robots', true)) {
            $paths['/robots.txt'] = 'The web server is answering /robots.txt itself before the site sees it. On Laravel Forge open the site, then Edit Files → Edit Nginx Configuration, delete the line "location = /robots.txt { access_log off; log_not_found off; }" and save.';
        }

        if (config('gadya-cms.seo.sitemap', true)) {
            $paths['/sitemap.xml'] = 'Check that seo.sitemap is on and nothing in public/ or the web server answers /sitemap.xml instead.';
        }

        if (config('gadya-cms.seo.llms', true)) {
            $paths['/llms.txt'] = 'Check that seo.llms is on and nothing in public/ or the web server answers /llms.txt instead.';
        }

        return $paths;
    }

    /** Where the primary domain points now, which every other domain should match. */
    private function serverAddress(): ?string
    {
        return $this->values($this->primaryDomain(), 'A')[0] ?? null;
    }

    private function sendsMailFrom(string $domain): bool
    {
        return Str::lower(Str::after((string) config('mail.from.address'), '@')) === $domain;
    }

    private function spfInclude(): string
    {
        return match ((string) config('mail.default')) {
            'postmark' => 'include:spf.mtasv.net',
            'mailgun' => 'include:mailgun.org',
            'ses', 'ses-v2', 'resend' => 'include:amazonses.com',
            'cloudflare' => 'include:(the record Cloudflare gives you for this domain)',
            default => 'include:(your email provider)',
        };
    }

    private function appHost(): string
    {
        return Str::of((string) parse_url((string) config('app.url'), PHP_URL_HOST))->lower()->replaceStart('www.', '')->toString() ?: 'localhost';
    }

    /**
     * @return list<string>
     */
    private function values(string $host, string $type): array
    {
        return $this->dns->values($host, $type);
    }
}
