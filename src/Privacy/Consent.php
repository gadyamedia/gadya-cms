<?php

namespace Gadya\Cms\Privacy;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Illuminate\Http\Request;

/**
 * What a visitor has agreed to, and what the site has promised to ask.
 *
 * The choice lives in one first-party cookie the banner writes in the
 * browser, so a page served from a cache still honours it; the server
 * reads the same cookie to leave the CMS's own visit counting out for
 * anyone who said no to analytics. A Global Privacy Control signal - the
 * `Sec-GPC: 1` header, or `navigator.globalPrivacyControl` in the browser -
 * is always a no to marketing: under the New Jersey Data Privacy Act it
 * is an opt-out of the sale of personal data and of targeted advertising.
 *
 * The settings are the site document's `privacy` key over the
 * `gadya-cms.privacy` config, so the wording is drafted and published like
 * any other words on the site.
 */
class Consent
{
    /** The top-level key of the site document the settings live under. */
    public const KEY = 'privacy';

    public const NECESSARY = 'necessary';

    public const ANALYTICS = 'analytics';

    public const MARKETING = 'marketing';

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly EditContext $editor,
    ) {}

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return [self::NECESSARY, self::ANALYTICS, self::MARKETING];
    }

    /**
     * The settings as the current request should see them.
     *
     * @return array{banner_enabled: bool, honour_gpc: bool, cookie: string, cookie_days: int, policy_url: string|null, heading: string, message: string, categories: array<string, string>}
     */
    public function settings(): array
    {
        $this->editor->boot();

        return $this->merge($this->repository->forRequest()[self::KEY] ?? null);
    }

    /**
     * The settings the live site is using, whoever is asking.
     *
     * @return array{banner_enabled: bool, honour_gpc: bool, cookie: string, cookie_days: int, policy_url: string|null, heading: string, message: string, categories: array<string, string>}
     */
    public function published(): array
    {
        return $this->merge($this->repository->published()[self::KEY] ?? null);
    }

    /**
     * @return array{banner_enabled: bool, honour_gpc: bool, cookie: string, cookie_days: int, policy_url: string|null, heading: string, message: string, categories: array<string, string>}
     */
    public function draft(): array
    {
        return $this->merge($this->repository->draft()[self::KEY] ?? null);
    }

    public function bannerEnabled(): bool
    {
        return $this->settings()['banner_enabled'];
    }

    public static function cookieName(): string
    {
        return (string) config('gadya-cms.privacy.cookie', 'gadya_consent') ?: 'gadya_consent';
    }

    /** Whether the browser sent a Global Privacy Control signal. */
    public function hasGpcSignal(Request $request): bool
    {
        return trim((string) $request->headers->get('Sec-GPC')) === '1';
    }

    /**
     * The visitor's saved choice, or null when she has not made one.
     *
     * @return array{necessary: bool, analytics: bool, marketing: bool, gpc: bool, at: string|null}|null
     */
    public function choice(Request $request): ?array
    {
        $raw = $request->cookies->get(self::cookieName());

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $decoded = json_decode(rawurldecode($raw), true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        return [
            self::NECESSARY => true,
            self::ANALYTICS => ($decoded[self::ANALYTICS] ?? false) === true,
            self::MARKETING => ($decoded[self::MARKETING] ?? false) === true,
            'gpc' => ($decoded['gpc'] ?? false) === true,
            'at' => is_string($decoded['at'] ?? null) ? $decoded['at'] : null,
        ];
    }

    /**
     * Whether something in a category may run for this visitor. Without
     * the banner switched on, the site behaves as it always did - except
     * that a Global Privacy Control signal still keeps marketing out.
     */
    public function allows(string $category, Request $request): bool
    {
        if ($category === self::NECESSARY) {
            return true;
        }

        $settings = $this->settings();

        if ($category === self::MARKETING && $settings['honour_gpc'] && $this->hasGpcSignal($request)) {
            return false;
        }

        $choice = $this->choice($request);

        if ($choice !== null) {
            return $choice[$category] ?? false;
        }

        return ! $settings['banner_enabled'];
    }

    /**
     * Whether the visitor has actively said no to a category. The CMS's
     * own visit counting sets no cookie and keeps no address, so it runs
     * until someone refuses analytics - and then stops for her.
     */
    public function refuses(string $category, Request $request): bool
    {
        $choice = $this->choice($request);

        return $choice !== null && ($choice[$category] ?? false) === false;
    }

    /**
     * What the portal is told at check-in.
     *
     * @return array{banner_enabled: bool, honours_gpc: bool}
     */
    public function summary(): array
    {
        $settings = $this->published();

        return [
            'banner_enabled' => $settings['banner_enabled'],
            'honours_gpc' => $settings['honour_gpc'],
        ];
    }

    /**
     * @return array{banner_enabled: bool, honour_gpc: bool, cookie: string, cookie_days: int, policy_url: string|null, heading: string, message: string, categories: array<string, string>}
     */
    private function merge(mixed $stored): array
    {
        $defaults = (array) config('gadya-cms.privacy', []);
        $stored = is_array($stored) ? array_filter($stored, fn ($value): bool => $value !== null && $value !== '') : [];

        $categories = [];

        foreach (self::categories() as $category) {
            $categories[$category] = (string) (($stored['categories'][$category] ?? null) ?: ($defaults['categories'][$category] ?? ''));
        }

        $policy = $stored['policy_url'] ?? $defaults['policy_url'] ?? null;

        return [
            'banner_enabled' => (bool) ($stored['banner_enabled'] ?? $defaults['banner_enabled'] ?? false),
            'honour_gpc' => (bool) ($stored['honour_gpc'] ?? $defaults['honour_gpc'] ?? true),
            'cookie' => self::cookieName(),
            'cookie_days' => max(1, (int) ($defaults['cookie_days'] ?? 365)),
            'policy_url' => is_string($policy) && $policy !== '' ? $policy : null,
            'heading' => (string) ($stored['heading'] ?? $defaults['heading'] ?? 'Your privacy choices'),
            'message' => (string) ($stored['message'] ?? $defaults['message'] ?? ''),
            'categories' => $categories,
        ];
    }
}
