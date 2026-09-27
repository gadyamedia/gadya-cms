<?php

namespace Gadya\Cms\Portal;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The business's Google reviews, as the Gadya Media portal holds them.
 *
 * The portal asks Google, so no Places key ever lives on a client's
 * server; this asks the portal at most every twelve hours and keeps the
 * answer, so a busy page never waits on either. A site that is not
 * paired, or a portal that does not answer, simply has no reviews to
 * show - the page is never broken for want of them.
 */
class Reviews
{
    public const PATH = '/api/connect/v1/reviews';

    public const CACHE_KEY = 'gadya-cms.portal.reviews';

    /** How long a failed ask is remembered, so a portal outage is not asked about on every page view. */
    private const RETRY_MINUTES = 30;

    public function __construct(private readonly PortalClient $portal) {}

    /**
     * The reviews worth showing: at or above the lowest rating the site
     * shows, newest as Google ordered them, no more than asked for. Null
     * when there is nothing to show at all.
     *
     * @return array{rating: float|null, count: int|null, review_url: string|null, reviews: list<array{author: string, rating: int, text: string, relative_time: string|null, time: string|null, profile_photo_url: string|null}>}|null
     */
    public function forDisplay(?int $limit = null, ?int $minRating = null): ?array
    {
        $all = $this->all();

        if ($all === null) {
            return null;
        }

        $minRating ??= (int) config('gadya-cms.portal.reviews.min_rating', 4);
        $limit ??= (int) config('gadya-cms.portal.reviews.limit', 6);

        $reviews = collect($all['reviews'])
            ->filter(fn (array $review): bool => $review['rating'] >= $minRating && $review['text'] !== '')
            ->take(max(0, $limit))
            ->values()
            ->all();

        if ($reviews === [] && $all['review_url'] === null) {
            return null;
        }

        return [...$all, 'reviews' => $reviews];
    }

    /**
     * Everything the portal returned, cleaned, from the cache when fresh.
     *
     * @return array{rating: float|null, count: int|null, review_url: string|null, reviews: list<array{author: string, rating: int, text: string, relative_time: string|null, time: string|null, profile_photo_url: string|null}>}|null
     */
    public function all(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached['data'] ?? null;
        }

        $data = $this->fetch();

        Cache::put(
            self::CACHE_KEY,
            ['data' => $data],
            $data === null ? now()->addMinutes(self::RETRY_MINUTES) : now()->addHours(max(1, (int) config('gadya-cms.portal.reviews.cache_hours', 12))),
        );

        return $data;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{rating: float|null, count: int|null, review_url: string|null, reviews: list<array<string, mixed>>}|null
     */
    private function fetch(): ?array
    {
        $connection = Connection::current();

        if ($connection === null) {
            return null;
        }

        try {
            $response = $this->portal->send($connection, 'GET', self::PATH);
        } catch (Throwable) {
            return null;
        }

        $data = $response->successful() ? $response->json('data') : null;

        if (! is_array($data)) {
            return null;
        }

        $reviewUrl = is_string($data['review_url'] ?? null) && filter_var($data['review_url'], FILTER_VALIDATE_URL) && str_starts_with($data['review_url'], 'https://')
            ? $data['review_url']
            : null;

        return [
            'rating' => is_numeric($data['rating'] ?? null) ? round((float) $data['rating'], 1) : null,
            'count' => is_numeric($data['count'] ?? null) ? (int) $data['count'] : null,
            'review_url' => $reviewUrl,
            'reviews' => collect(is_array($data['reviews'] ?? null) ? $data['reviews'] : [])
                ->filter(fn ($review): bool => is_array($review) && is_numeric($review['rating'] ?? null))
                ->map(fn (array $review): array => [
                    'author' => trim((string) ($review['author'] ?? '')) ?: 'A Google user',
                    'rating' => max(1, min(5, (int) $review['rating'])),
                    'text' => trim((string) ($review['text'] ?? '')),
                    'relative_time' => is_string($review['relative_time'] ?? null) ? $review['relative_time'] : null,
                    'time' => is_string($review['time'] ?? null) ? $review['time'] : null,
                    'profile_photo_url' => is_string($review['profile_photo_url'] ?? null) && str_starts_with($review['profile_photo_url'], 'https://') ? $review['profile_photo_url'] : null,
                ])
                ->values()
                ->all(),
        ];
    }
}
