<?php

namespace Gadya\Cms\Content;

/**
 * The site document as a visitor should see it.
 *
 * Hiding a page in the panel used to only stop that one address resolving,
 * which left every menu entry and card still pointing at it. This closes
 * that: hiding a location takes it out of the navigation, out of the
 * cards on the home page and the locations index, and off the contact
 * map, so one switch really does hide it everywhere.
 *
 * Prose is deliberately untouched. A sentence that happens to mention a
 * place by name is the client's copy to change, and silently rewriting it
 * would be worse than leaving it.
 */
class PublicDocument
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function from(array $document): array
    {
        $hiddenPages = $this->hiddenPageSlugs($document);
        $hiddenLocations = $this->hiddenLocationKeys($document, $hiddenPages);

        if ($hiddenPages === [] && $hiddenLocations === []) {
            return $document;
        }

        $document['locations'] = $this->withoutHiddenLocations($document['locations'] ?? [], $hiddenLocations);
        $document['nav'] = $this->filterNavigation($document['nav'] ?? [], $hiddenPages, $hiddenLocations);
        $document['contact_locations'] = $this->filterContactLocations($document['contact_locations'] ?? [], $hiddenLocations);
        $document['pages'] = $this->filterPageCards($document['pages'] ?? [], $hiddenLocations);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function hiddenPageSlugs(array $document): array
    {
        $hidden = [];

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (is_array($page) && ($page['status'] ?? PageRegistry::STATUS_PUBLISHED) === PageRegistry::STATUS_ARCHIVED) {
                $hidden[] = (string) $slug;
            }
        }

        return $hidden;
    }

    /**
     * A location key is hidden when the page it points at is hidden, or
     * when it points at nothing at all.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $hiddenPages
     * @return list<string>
     */
    private function hiddenLocationKeys(array $document, array $hiddenPages): array
    {
        $hidden = [];

        foreach ($document['locations'] ?? [] as $key => $slug) {
            if (! is_string($slug) || in_array($slug, $hiddenPages, true) || ! isset($document['pages'][$slug])) {
                $hidden[] = (string) $key;
            }
        }

        return $hidden;
    }

    /**
     * @param  array<string, mixed>  $locations
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    private function withoutHiddenLocations(array $locations, array $hidden): array
    {
        return array_diff_key($locations, array_flip($hidden));
    }

    /**
     * @param  array<array-key, mixed>  $nav
     * @param  list<string>  $hiddenPages
     * @param  list<string>  $hiddenLocations
     * @return array<array-key, mixed>
     */
    private function filterNavigation(array $nav, array $hiddenPages, array $hiddenLocations): array
    {
        $kept = [];

        foreach ($nav as $item) {
            if (! is_array($item)) {
                continue;
            }

            $children = $this->filterNavigation($item['children'] ?? [], $hiddenPages, $hiddenLocations);

            if ($this->pointsAtHiddenPage($item, $hiddenPages, $hiddenLocations)) {
                /*
                 * A heading that only exists to open its sub items goes
                 * with them; one that is itself a link is dropped whatever
                 * its children are doing, because the link is dead.
                 */
                continue;
            }

            if (array_key_exists('children', $item)) {
                $item['children'] = $children;
            }

            if (($item['slug'] ?? null) === null && $children === []) {
                continue;
            }

            $kept[] = $item;
        }

        return array_values($kept);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $hiddenPages
     * @param  list<string>  $hiddenLocations
     */
    private function pointsAtHiddenPage(array $item, array $hiddenPages, array $hiddenLocations): bool
    {
        if (isset($item['location']) && in_array($item['location'], $hiddenLocations, true)) {
            return true;
        }

        return isset($item['slug']) && in_array($item['slug'], $hiddenPages, true);
    }

    /**
     * Contact entries carry a `position`, which is really a hint for where
     * the pin sits on the map rather than a link to a page. It is used as a
     * fallback so the common case works without extra data, but an explicit
     * `location` set from the panel always wins.
     *
     * @param  array<array-key, mixed>  $contactLocations
     * @param  list<string>  $hidden
     * @return array<array-key, mixed>
     */
    private function filterContactLocations(array $contactLocations, array $hidden): array
    {
        return array_filter($contactLocations, function ($entry) use ($hidden): bool {
            if (! is_array($entry)) {
                return false;
            }

            return ! in_array($entry['location'] ?? $entry['position'] ?? null, $hidden, true);
        });
    }

    /**
     * Card lists anywhere in the document that point at a location.
     *
     * Keys are deliberately preserved rather than renumbered: a template
     * writes the array index into the live editor's path, so renumbering
     * here would silently point an edit at the wrong card.
     *
     * @param  array<string, mixed>  $pages
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    private function filterPageCards(array $pages, array $hidden): array
    {
        if ($hidden === []) {
            return $pages;
        }

        foreach ($pages as $slug => $page) {
            if (! is_array($page)) {
                continue;
            }

            foreach ($page as $key => $value) {
                if (! is_array($value) || ! $this->isCardList($value)) {
                    continue;
                }

                $pages[$slug][$key] = array_filter(
                    $value,
                    fn ($card): bool => ! in_array($card['location'] ?? null, $hidden, true),
                );
            }
        }

        return $pages;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function isCardList(array $value): bool
    {
        foreach ($value as $card) {
            if (is_array($card) && array_key_exists('location', $card)) {
                return true;
            }
        }

        return false;
    }
}
