<?php

namespace Gadya\Cms\Analytics;

use Illuminate\Http\Request;

/**
 * Whether a request is a machine rather than a person: crawlers, uptime
 * monitors, HTTP libraries, link-preview fetchers, AI and SEO crawlers,
 * our own checks, anything with no user agent at all, and browsers
 * prefetching a page nobody has opened yet. Add to the list with
 * `gadya-cms.analytics.bot_patterns`.
 */
class BotDetector
{
    /** @var array<string, string> */
    public const PATTERNS = [
        'generic' => 'bot|crawl|spider|slurp|scan|monitor|check|probe|fetch|preview|headless|lighthouse|pagespeed|gtmetrix|curl|wget|httpie|postman|insomnia',
        'monitoring' => 'uptime|pingdom|statuscake|site24x7|betteruptime|better stack|datadog|newrelic|synthetic|healthcheck|health-check',
        'libraries' => 'python-requests|python-urllib|aiohttp|httpx|go-http-client|java/|okhttp|apache-httpclient|axios|node-fetch|undici|libwww|guzzle|scrapy|php/',
        'previews' => 'facebookexternalhit|facebot|whatsapp|telegram|slackbot|discord|twitterbot|linkedinbot|skypeuri|embedly|pinterest|redditbot|quora link preview',
        'crawlers' => 'gptbot|chatgpt-user|oai-searchbot|claudebot|claude-web|anthropic|perplexity|bytespider|ccbot|amazonbot|applebot|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baidu|duckduckbot|bingpreview|google-inspectiontool|googleother|adsbot',
        'gadya' => 'gadya',
    ];

    public static function isBot(Request $request): bool
    {
        return self::isBotAgent((string) $request->userAgent())
            || self::isPrefetch($request);
    }

    public static function isBotAgent(string $userAgent): bool
    {
        $userAgent = trim($userAgent);

        if ($userAgent === '') {
            return true;
        }

        return (bool) preg_match(self::pattern(), $userAgent);
    }

    public static function isPrefetch(Request $request): bool
    {
        return (bool) preg_match('/^(prefetch|prerender)/i', trim((string) $request->headers->get('Purpose')))
            || (bool) preg_match('/^prefetch/i', trim((string) $request->headers->get('Sec-Purpose')));
    }

    private static function pattern(): string
    {
        $fragments = [...array_values(self::PATTERNS)];

        foreach ((array) config('gadya-cms.analytics.bot_patterns', []) as $extra) {
            if (is_string($extra) && $extra !== '') {
                $fragments[] = $extra;
            }
        }

        return '~'.implode('|', array_map(fn (string $fragment): string => str_replace('~', '\~', $fragment), $fragments)).'~i';
    }
}
