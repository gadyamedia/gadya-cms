<?php

namespace Gadya\Cms\Privacy;

/**
 * The third-party trackers a site's templates load, found by the
 * addresses and calls their snippets always contain. Enough to tell a
 * site that pastes in Google Analytics or a Meta pixel that it now needs
 * to ask first.
 */
class TrackerScan
{
    /** @var array<string, list<string>> */
    public const TRACKERS = [
        'Google Analytics / Tag Manager' => ['googletagmanager.com', 'google-analytics.com', 'gtag('],
        'Google Ads' => ['googleadservices.com', 'googlesyndication.com', 'doubleclick.net'],
        'Meta Pixel' => ['connect.facebook.net', 'fbq('],
        'TikTok Pixel' => ['analytics.tiktok.com', 'ttq.'],
        'LinkedIn Insight' => ['snap.licdn.com', 'px.ads.linkedin.com'],
        'Microsoft Clarity / Bing' => ['clarity.ms', 'bat.bing.com'],
        'Hotjar' => ['static.hotjar.com', 'hotjar.com/c/'],
        'Pinterest Tag' => ['s.pinimg.com/ct', 'pintrk('],
        'Snap Pixel' => ['sc-static.net/scevent', 'snaptr('],
    ];

    /**
     * @return list<string>
     */
    public static function found(string $templates): array
    {
        $found = [];

        foreach (self::TRACKERS as $name => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($templates, $needle)) {
                    $found[] = $name;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Whether the site asks before its trackers run: no trackers at all,
     * or the banner switched on, placed, and the tags wrapped to wait.
     *
     * @return array{passed: bool, fix: string}
     */
    public static function check(string $templates, bool $bannerEnabled): array
    {
        $found = self::found($templates);

        if ($found === []) {
            return ['passed' => true, 'fix' => ''];
        }

        $missing = array_values(array_filter([
            $bannerEnabled ? null : 'switch the banner on under Settings → Privacy choices',
            str_contains($templates, 'x-gadya-cms::consent-banner') ? null : 'add <x-gadya-cms::consent-banner /> before </body> and <x-gadya-cms::privacy-choices-link /> to the footer',
            str_contains($templates, 'x-gadya-cms::consented-script') ? null : 'wrap each tag in <x-gadya-cms::consented-script category="marketing">',
        ]));

        return [
            'passed' => $missing === [],
            'fix' => 'The templates load '.implode(', ', $found).' without asking first (New Jersey Data Privacy Act). To fix: '.implode('; ', $missing).'. See docs/privacy.md.',
        ];
    }
}
