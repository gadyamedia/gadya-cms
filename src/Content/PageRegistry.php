<?php

namespace Gadya\Cms\Content;

use Gadya\Cms\Models\Page;

class PageRegistry
{
    public const STATUS_PUBLISHED = Page::STATUS_PUBLISHED;

    public const STATUS_ARCHIVED = Page::STATUS_ARCHIVED;

    public function __construct(private readonly SiteContentRepository $repository) {}

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
     * The one true public address for a page.
     *
     * A location is reachable both at its own slug and under
     * /party-places, which is two addresses for one page: bad for search
     * engines, and confusing in the panel, where the address shown was
     * never the one the client sees in the browser. The location route is
     * the canonical one, and the bare slug redirects to it.
     *
     * @param  array<string, mixed>  $document
     */
    public function publicPathFor(string $slug, array $document): string
    {
        $locationKey = $this->locationKeyFor($slug, $document);

        if ($locationKey !== null) {
            return '/'.trim(parse_url(route('locations.show', $locationKey), PHP_URL_PATH) ?? '', '/');
        }

        return $slug === 'home' ? '/' : '/'.$slug;
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
