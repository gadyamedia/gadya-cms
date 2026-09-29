<?php

namespace Gadya\Cms\Forms;

use Closure;
use Gadya\Cms\Privacy\Consent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where an enquiry came from: the ad campaign that brought the visitor,
 * the page they landed on, the page they sent it from, their language and
 * which of the site's brands they were looking at.
 *
 * A business asks "which advert brought this lead?" of every enquiry, and
 * the site that handed its form over to the CMS must still be able to
 * answer. It is kept with the enquiry as information the enquiry needs -
 * like the page it was sent from - not as analytics: no identifier, no
 * cookie of its own, nothing kept about someone who does not write in.
 * See docs/privacy.md.
 *
 * First touch wins. The forms script notes the campaign, the referrer and
 * the landing page in the tab's sessionStorage on the first page of the
 * visit it sees and posts them with the form; the server falls back to
 * what the session noted on the visitor's first request, then to the
 * query string of the page the form was sent from.
 */
class Attribution
{
    /** The input the forms script posts what it noted under. */
    public const INPUT = '_attribution';

    /** Where the session keeps the first request of the visit. */
    public const SESSION = 'gadya-cms.first-touch';

    /** Campaign and ad-click parameters, as they appear in an address. */
    public const PARAMETERS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid'];

    /** What the script may post, beside the parameters. */
    public const VISIT = ['referrer', 'landing_page'];

    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly Consent $consent,
    ) {}

    /**
     * Note the first request of a visit in the session: its campaign, the
     * site that sent the visitor and the page they landed on.
     */
    public function remember(Request $request): void
    {
        if (! $request->hasSession() || $request->session()->has(self::SESSION)) {
            return;
        }

        $noted = ['landing_page' => $this->limit($request->fullUrl(), 500)];
        $referrer = $this->externalReferrer($request->headers->get('referer'), $request);

        if ($referrer !== null) {
            $noted['referrer'] = $referrer;
        }

        foreach (self::PARAMETERS as $parameter) {
            if (is_string($request->query($parameter)) && trim($request->query($parameter)) !== '') {
                $noted[$parameter] = $this->limit($request->query($parameter), 200);
            }
        }

        $request->session()->put(self::SESSION, $noted);
    }

    /**
     * Everything known about where this enquiry came from, for
     * `meta.attribution`.
     *
     * @return array<string, string>
     */
    public function capture(Request $request, ?string $locale = null): array
    {
        if (! config('gadya-cms.forms.builder.attribution.enabled', true)) {
            return [];
        }

        $posted = is_array($request->input(self::INPUT)) ? $request->input(self::INPUT) : [];
        $session = $request->hasSession() ? (array) $request->session()->get(self::SESSION, []) : [];
        $page = $this->pageUrl($request);
        $query = [];

        parse_str((string) parse_url($page ?? '', PHP_URL_QUERY), $query);

        $record = [];

        foreach (self::PARAMETERS as $parameter) {
            $record[$parameter] = $this->first(
                $posted[$parameter] ?? null,
                $session[$parameter] ?? null,
                $query[$parameter] ?? null,
                $request->hasSession() ? $request->session()->get('gadya-cms.attribution.'.$parameter) : null,
            );
        }

        $record['referrer'] = $this->first(
            $this->externalReferrer($posted['referrer'] ?? null, $request),
            $session['referrer'] ?? null,
        );
        $record['landing_page'] = $this->first($this->sameSite($posted['landing_page'] ?? null, $request), $session['landing_page'] ?? null, $page);
        $record['page_url'] = $page;
        $record['locale'] = $locale ?? app(FormLocale::class)->current();
        $record['site'] = $this->site($request);
        $record['user_agent'] = $this->limit($request->userAgent(), 300);
        $record['ip'] = $this->ip($request);

        return array_filter(array_map(fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? $this->limit($value, 500) : null, $record), fn (?string $value): bool => $value !== null);
    }

    /**
     * Which of the site's brands the visitor was on. A site with one brand
     * is the CMS site's own key; one serving several from the same app
     * says which, by host (`['aleksey.com' => 'aleksey']`), or with a
     * closure or an invokable class given the request.
     */
    public function site(Request $request): ?string
    {
        $resolver = config('gadya-cms.forms.builder.attribution.site');
        $resolved = null;

        if (is_array($resolver)) {
            $host = strtolower($request->getHost());
            $resolved = $resolver[$host] ?? $resolver[Str::after($host, 'www.')] ?? null;
        } elseif ($resolver instanceof Closure) {
            $resolved = rescue(fn (): mixed => $resolver($request), null, report: true);
        } elseif (is_string($resolver) && class_exists($resolver)) {
            $resolved = rescue(fn (): mixed => app($resolver)($request), null, report: true);
        } elseif (is_string($resolver) && $resolver !== '') {
            $resolved = $resolver;
        }

        if (is_scalar($resolved) && trim((string) $resolved) !== '') {
            return $this->limit((string) $resolved, 120);
        }

        return $this->siteContext->get()?->key;
    }

    /**
     * The visitor's address, as far as the site keeps it: in full, with the
     * last part blanked (`masked`), or not at all (`none`). Someone who
     * refused analytics, or whose browser asks not to be tracked, has it
     * masked whatever the setting.
     */
    public function ip(Request $request): ?string
    {
        $mode = (string) config('gadya-cms.forms.builder.attribution.ip', 'full');
        $ip = $request->ip();

        if ($ip === null || $mode === 'none') {
            return null;
        }

        $private = rescue(fn (): bool => $this->consent->refuses(Consent::ANALYTICS, $request) || $this->consent->hasGpcSignal($request), false, report: false);

        return $mode === 'masked' || $private ? self::mask($ip) : $ip;
    }

    public static function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.0', $ip);
        }

        $packed = @inet_pton($ip);

        return $packed === false ? '' : (string) inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));
    }

    /**
     * The one word for where a lead came from: the campaign's source, else
     * the ad network whose click brought them, else the site that sent
     * them, else "direct".
     *
     * @param  array<string, string>  $attribution
     */
    public static function source(array $attribution): string
    {
        return match (true) {
            filled($attribution['utm_source'] ?? null) => $attribution['utm_source'],
            filled($attribution['gclid'] ?? null) => 'google-ads',
            filled($attribution['fbclid'] ?? null) => 'facebook-ads',
            filled($attribution['msclkid'] ?? null) => 'microsoft-ads',
            filled($attribution['referrer'] ?? null) => (string) (parse_url($attribution['referrer'], PHP_URL_HOST) ?: $attribution['referrer']),
            default => 'direct',
        };
    }

    /**
     * The words for each part of a record, in the order the inbox and the
     * spreadsheets show them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'utm_source' => 'Campaign source',
            'utm_medium' => 'Campaign medium',
            'utm_campaign' => 'Campaign',
            'utm_term' => 'Campaign term',
            'utm_content' => 'Campaign content',
            'gclid' => 'Google Ads click',
            'fbclid' => 'Facebook click',
            'msclkid' => 'Microsoft Ads click',
            'referrer' => 'Came from',
            'landing_page' => 'Landed on',
            'page_url' => 'Sent from',
            'locale' => 'Language',
            'site' => 'Site',
            'user_agent' => 'Browser',
            'ip' => 'Address',
        ];
    }

    /**
     * A record with its words, leaving out what it does not have.
     *
     * @param  array<string, mixed>  $attribution
     * @return array<string, string>
     */
    public static function labelled(array $attribution): array
    {
        $labelled = [];

        foreach (self::labels() as $key => $label) {
            if (is_string($attribution[$key] ?? null) && $attribution[$key] !== '') {
                $labelled[$label] = $attribution[$key];
            }
        }

        return $labelled;
    }

    /** The page the form was sent from, in full. */
    private function pageUrl(Request $request): ?string
    {
        $referer = $this->sameSite($request->headers->get('referer'), $request);
        $path = $request->input('_path');

        if ($referer !== null) {
            return $referer;
        }

        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? url($path) : null;
    }

    private function externalReferrer(mixed $url, Request $request): ?string
    {
        if (! is_string($url) || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) !== 0 ? $this->limit($url, 500) : null;
    }

    private function sameSite(mixed $url, Request $request): ?string
    {
        if (! is_string($url) || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0 ? $this->limit($url, 500) : null;
    }

    private function first(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function limit(mixed $value, int $length): ?string
    {
        return is_string($value) ? Str::limit(trim($value), $length, '') : null;
    }
}
