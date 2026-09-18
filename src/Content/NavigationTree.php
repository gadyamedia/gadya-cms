<?php

namespace Gadya\Cms\Content;

/**
 * The menu as a tree of parents and children.
 *
 * The document used to describe nesting with a `group` label repeated on
 * every child, which is awkward to edit and impossible to reorder. Items
 * now carry their own `children`, the way a menu is actually shaped.
 *
 * Documents written the old way are converted on read, so a site that has
 * not been re-saved since keeps working and needs no migration.
 */
class NavigationTree
{
    public const PRIMARY = 'primary';

    public const SIDE_START = 'start';

    public const SIDE_END = 'end';

    /**
     * The menus a site has, by key. A site that never asked for more than
     * one has the one, which is the `nav` it always had.
     *
     * @return array<string, string>
     */
    public static function menus(): array
    {
        $menus = (array) config('gadya-cms.navigation.menus', []);
        $menus = array_filter($menus, 'is_string');

        return $menus === [] ? [self::PRIMARY => 'Main menu'] : $menus;
    }

    /**
     * Where a menu lives in the document: the main one is `nav`, as it
     * always was, and the rest sit under `menus`.
     *
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    public function forMenu(array $document, string $key = self::PRIMARY): array
    {
        $items = $key === self::PRIMARY
            ? ($document['nav'] ?? [])
            : ($document['menus'][$key] ?? []);

        return $this->fromDocument(is_array($items) ? $items : [], $this->locationsParentSlug($document));
    }

    /**
     * @param  array<array-key, mixed>  $nav
     * @return list<array<string, mixed>>
     */
    public function fromDocument(array $nav, ?string $locationsParent = null): array
    {
        $tree = $this->isLegacy($nav)
            ? $this->fromGroups($nav, $locationsParent)
            : $this->normalise($nav);

        return array_map($this->withPlacementDefaults(...), $tree);
    }

    /**
     * An item the document has no placement for takes the application's
     * default, so a menu written before sides existed still lands where it
     * always did.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withPlacementDefaults(array $item): array
    {
        $slug = $item['slug'] ?? null;

        if (! array_key_exists('side', $item)) {
            $item['side'] = is_string($slug) && in_array($slug, (array) config('gadya-cms.navigation.default_end_slugs', []), true)
                ? self::SIDE_END
                : self::SIDE_START;
        }

        if (! array_key_exists('highlight', $item)) {
            $item['highlight'] = is_string($slug)
                && in_array($slug, (array) config('gadya-cms.navigation.default_highlight_slugs', []), true);
        }

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    public function onSide(array $tree, string $side): array
    {
        return array_values(array_filter(
            $tree,
            fn (array $item): bool => ($item['side'] ?? self::SIDE_START) === $side,
        ));
    }

    /**
     * The menu entry the locations belong under: whichever one points at
     * the page that lists them.
     *
     * @param  array<string, mixed>  $document
     */
    public function locationsParentSlug(array $document): ?string
    {
        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (is_array($page) && ($page['type'] ?? null) === config('gadya-cms.navigation.locations_type', 'locations')) {
                return (string) $slug;
            }
        }

        return null;
    }

    /**
     * Flatten a tree back to the shape the rest of the document expects,
     * so anything reading the menu as a plain list still sees every entry.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    public function flatten(array $tree): array
    {
        $flat = [];

        foreach ($tree as $item) {
            $children = $item['children'] ?? [];
            unset($item['children']);

            $flat[] = $item;

            foreach ($children as $child) {
                $flat[] = [...$child, 'group' => $item['label'] ?? null];
            }
        }

        return $flat;
    }

    /**
     * @param  array<array-key, mixed>  $nav
     */
    private function isLegacy(array $nav): bool
    {
        foreach ($nav as $item) {
            if (is_array($item) && array_key_exists('children', $item)) {
                return false;
            }
        }

        foreach ($nav as $item) {
            if (is_array($item) && isset($item['group'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a flat, `group`-labelled menu into a tree. The parent appears
     * where its first child did, so the order the client sees does not
     * jump around the first time she opens the menu editor.
     *
     * @param  array<array-key, mixed>  $nav
     * @return list<array<string, mixed>>
     */
    private function fromGroups(array $nav, ?string $locationsParent): array
    {
        $tree = [];
        $parentAt = [];
        $locationsAt = null;

        foreach ($nav as $item) {
            if (! is_array($item) || ! isset($item['label'])) {
                continue;
            }

            $group = $item['group'] ?? null;
            unset($item['group']);

            /*
             * The entry that lists the locations is a menu in its own
             * right, and each place belongs under it - not alongside the
             * package pages, which is where a flat `group` label put them.
             */
            if ($locationsParent !== null && ($item['slug'] ?? null) === $locationsParent) {
                $locationsAt = count($tree);
                $tree[] = [...$item, 'children' => []];

                continue;
            }

            if ($locationsAt !== null && isset($item['location'])) {
                $tree[$locationsAt]['children'][] = $item;

                continue;
            }

            if (! is_string($group) || $group === '') {
                $tree[] = $item;

                continue;
            }

            if (! isset($parentAt[$group])) {
                $parentAt[$group] = count($tree);
                $tree[] = ['label' => $group, 'children' => []];
            }

            $tree[$parentAt[$group]]['children'][] = $item;
        }

        return array_values($tree);
    }

    /**
     * @param  array<array-key, mixed>  $nav
     * @return list<array<string, mixed>>
     */
    private function normalise(array $nav): array
    {
        $tree = [];

        foreach ($nav as $item) {
            if (! is_array($item) || ! isset($item['label'])) {
                continue;
            }

            $children = [];

            foreach ($item['children'] ?? [] as $child) {
                if (is_array($child) && isset($child['label'])) {
                    $children[] = $child;
                }
            }

            $item['children'] = $children;
            $tree[] = $item;
        }

        return $tree;
    }
}
