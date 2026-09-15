<?php

namespace Gadya\Cms\Content;

use DateTimeInterface;
use Gadya\Cms\Contracts\ResolvesPagePaths;
use Gadya\Cms\Models\Page;
use Illuminate\Support\Carbon;

class PageRegistry
{
    public const STATUS_PUBLISHED = Page::STATUS_PUBLISHED;

    public const STATUS_ARCHIVED = Page::STATUS_ARCHIVED;

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly ResolvesPagePaths $paths,
    ) {}

    /**
     * Slugs the client may never take: those that collide with a real route,
     * plus the prefixes and existing pages it would be confusing or unsafe
     * to shadow.
     *
     * @return list<string>
     */
    public function reservedSlugs(): array
    {
        /** @var list<string> $slugs */
        $slugs = config('gadya-cms.pages.reserved_slugs', []);

        return $slugs;
    }

    /**
     * Page types the client may choose. Other types in the document are
     * special-cased by controllers or views and are not safe to create
     * freely.
     *
     * @return list<string>
     */
    public function creatableTypes(): array
    {
        /** @var list<string> $types */
        $types = config('gadya-cms.pages.creatable_types', ['content']);

        return $types;
    }

    /**
     * @return list<string>
     */
    public function sectionTypes(): array
    {
        /** @var list<string> $types */
        $types = config('gadya-cms.pages.section_types', []);

        return $types;
    }

    public function isReserved(string $slug): bool
    {
        return in_array($slug, $this->reservedSlugs(), true);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function isArchived(array $page): bool
    {
        return ($page['status'] ?? self::STATUS_PUBLISHED) === self::STATUS_ARCHIVED;
    }

    /**
     * A page a visitor should not see right now: hidden by the client, or
     * outside the dates she scheduled it for.
     *
     * @param  array<string, mixed>  $page
     */
    public function isHidden(array $page, ?DateTimeInterface $at = null): bool
    {
        return $this->isArchived($page) || ! $this->isWithinSchedule($page, $at);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function isWithinSchedule(array $page, ?DateTimeInterface $at = null): bool
    {
        $now = ($at ?? now())->getTimestamp();

        $from = $this->timestamp($page['publish_at'] ?? null);
        $until = $this->timestamp($page['unpublish_at'] ?? null);

        if ($from !== null && $from > $now) {
            return false;
        }

        return $until === null || $until > $now;
    }

    /**
     * Visible later rather than now: a page with a publish date still to come.
     *
     * @param  array<string, mixed>  $page
     */
    public function isScheduled(array $page): bool
    {
        $from = $this->timestamp($page['publish_at'] ?? null);

        return ! $this->isArchived($page) && $from !== null && $from > now()->getTimestamp();
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return rescue(fn (): int => Carbon::parse($value)->getTimestamp(), null, report: false);
    }

    /**
     * Resolve a slug that has been renamed. Follows a short chain so that a
     * page renamed twice still resolves, and stops rather than looping if
     * the data ever contains a cycle.
     */
    public function resolveRedirect(string $slug): ?string
    {
        $redirects = $this->repository->forRequest()['redirects'] ?? [];

        if (! is_array($redirects)) {
            return null;
        }

        $seen = [];
        $current = $slug;

        while (isset($redirects[$current]) && is_string($redirects[$current])) {
            if (isset($seen[$current])) {
                return null;
            }

            $seen[$current] = true;
            $current = $redirects[$current];
        }

        return $current === $slug ? null : $current;
    }

    /**
     * The one true public address for a page, as the application serves
     * it. Delegated because only the application knows its routes: a site
     * that nests some pages under a prefix binds its own resolver.
     *
     * @param  array<string, mixed>  $document
     */
    public function publicPathFor(string $slug, array $document): string
    {
        return $this->paths->publicPathFor($slug, $document);
    }

    public function publicUrlFor(string $slug, array $document): string
    {
        return url($this->publicPathFor($slug, $document));
    }

    /**
     * The location key a page slug belongs to, if it is a location.
     *
     * @param  array<string, mixed>  $document
     */
    public function locationKeyFor(string $slug, array $document): ?string
    {
        foreach ($document['locations'] ?? [] as $key => $pageSlug) {
            if ($pageSlug === $slug) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function draftDocument(): array
    {
        return $this->repository->draft();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function editablePages(): array
    {
        $pages = $this->repository->draft()['pages'] ?? [];

        return is_array($pages) ? $pages : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function navigation(): array
    {
        $nav = $this->repository->draft()['nav'] ?? [];

        return is_array($nav) ? $nav : [];
    }
}
