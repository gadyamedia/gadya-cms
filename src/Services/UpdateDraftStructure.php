<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\PageTypes;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Form;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UpdateDraftStructure
{
    public function __construct(private readonly SiteContentRepository $repository) {}

    /**
     * @return list<string>
     */
    public function sectionTypes(): array
    {
        return app(PageTypes::class)->sectionTypesFor(null);
    }

    /**
     * @param  array<string, string>  $item
     */
    public function addItem(string $sectionPath, array $item): void
    {
        $document = $this->repository->draft();
        $items = $this->itemsAt($document, $sectionPath);
        /* A key outlives renames and reorders, so a site can sync the item elsewhere. */
        $items[] = [...Arr::only($item, ['title', 'text', 'image']), 'key' => (string) Str::ulid()];

        $this->writeItems($document, $sectionPath, $items);
    }

    public function removeItem(string $sectionPath, int $index): void
    {
        $document = $this->repository->draft();
        $items = $this->itemsAt($document, $sectionPath);

        if (! array_key_exists($index, $items)) {
            throw new InvalidArgumentException("There is no item at index {$index}.");
        }

        unset($items[$index]);

        $this->writeItems($document, $sectionPath, array_values($items));
    }

    /**
     * @param  list<int>  $order
     */
    public function reorderItems(string $sectionPath, array $order): void
    {
        $document = $this->repository->draft();
        $items = $this->itemsAt($document, $sectionPath);

        $this->assertIsPermutation($order, count($items));

        $this->writeItems($document, $sectionPath, array_map(fn (int $index): array => $items[$index], $order));
    }

    public function addSection(string $pageSlug, string $type, string $title): void
    {
        if (! in_array($type, $this->sectionTypes(), true)) {
            throw new InvalidArgumentException("[{$type}] is not a supported section type.");
        }

        $document = $this->repository->draft();
        $sections = $this->sectionsAt($document, $pageSlug);

        $sections[] = match ($type) {
            'gallery' => ['title' => $title, 'type' => $type, 'images' => []],
            'form' => ['title' => $title, 'type' => $type, 'form' => ''],
            default => ['title' => $title, 'type' => $type, 'items' => []],
        };

        $this->writeSections($document, $pageSlug, $sections);
    }

    /**
     * Put another form in a Form section, from the live editor. Only a
     * published form of this site can be chosen, and only into a section
     * that is a Form section.
     */
    public function chooseForm(string $sectionPath, string $slug): void
    {
        $document = $this->repository->draft();
        $section = Arr::get($document, $sectionPath);

        if (! is_array($section) || ($section['type'] ?? null) !== 'form') {
            throw new InvalidArgumentException("[{$sectionPath}] is not a form section.");
        }

        if (Form::findLive($slug) === null) {
            throw new InvalidArgumentException('That form is not published.');
        }

        Arr::set($document, $sectionPath.'.form', $slug);
        $this->repository->saveDraft($document);
    }

    public function removeSection(string $pageSlug, int $index): void
    {
        $document = $this->repository->draft();
        $sections = $this->sectionsAt($document, $pageSlug);

        if (! array_key_exists($index, $sections)) {
            throw new InvalidArgumentException("There is no section at index {$index}.");
        }

        unset($sections[$index]);

        $this->writeSections($document, $pageSlug, array_values($sections));
    }

    /**
     * @param  list<int>  $order
     */
    public function reorderSections(string $pageSlug, array $order): void
    {
        $document = $this->repository->draft();
        $sections = $this->sectionsAt($document, $pageSlug);

        $this->assertIsPermutation($order, count($sections));

        $this->writeSections($document, $pageSlug, array_map(fn (int $index): array => $sections[$index], $order));
    }

    /**
     * The section designs "+ Add a section here" offers on a page: those in `pages.section_designs` that
     * the page's type allows.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function designsFor(?string $pageType): array
    {
        /** @var array<string, array{label: string, description: string}> $designs */
        $designs = (array) config('gadya-cms.pages.section_designs', []);
        $allowed = app(PageTypes::class)->sectionTypesFor($pageType);

        return array_filter($designs, fn (mixed $design, string $type): bool => is_array($design) && in_array($type, $allowed, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Put a new section in a chosen design at a chosen spot, from the live editor. It starts with one
     * sample item to type over, and a page that has never had sections, such as a home page, gets its
     * first. Returns where the section landed.
     */
    public function insertSection(string $pageSlug, string $type, int $position, string $title = 'New section'): int
    {
        $document = $this->repository->draft();
        $page = Arr::get($document, "pages.{$pageSlug}");

        if (! is_array($page)) {
            throw new InvalidArgumentException("[{$pageSlug}] is not an editable page.");
        }

        if (! array_key_exists($type, $this->designsFor($page['type'] ?? null))) {
            throw new InvalidArgumentException("[{$type}] cannot be added to this page.");
        }

        $sections = is_array($page['sections'] ?? null) ? array_values($page['sections']) : [];
        $position = max(0, min($position, count($sections)));
        $starter = ['title' => 'New item', 'text' => 'Describe it here.', 'key' => (string) Str::ulid()];

        array_splice($sections, $position, 0, [match ($type) {
            'gallery' => ['title' => $title, 'type' => $type, 'images' => []],
            'form' => ['title' => $title, 'type' => $type, 'form' => ''],
            default => ['title' => $title, 'type' => $type, 'items' => [$starter]],
        }]);

        $this->writeSections($document, $pageSlug, $sections);

        return $position;
    }

    /**
     * Swap a section with its neighbour, one step up (-1) or down (+1).
     */
    public function moveSection(string $pageSlug, int $index, int $step): void
    {
        $document = $this->repository->draft();
        $sections = array_values($this->sectionsAt($document, $pageSlug));
        $target = $index + $step;

        if (! array_key_exists($index, $sections) || ! array_key_exists($target, $sections)) {
            throw new InvalidArgumentException('That section cannot move any further.');
        }

        [$sections[$index], $sections[$target]] = [$sections[$target], $sections[$index]];

        $this->writeSections($document, $pageSlug, $sections);
    }

    /**
     * An empty photo slot at the end of a gallery, for the photo library to fill. The inline editor only
     * fills a slot that exists, so a gallery grows this way.
     */
    public function addPhotoSlot(string $sectionPath): void
    {
        $document = $this->repository->draft();
        $section = Arr::get($document, $sectionPath);

        if (! is_array($section) || ($section['type'] ?? null) !== 'gallery') {
            throw new InvalidArgumentException("[{$sectionPath}] is not a photo gallery.");
        }

        Arr::set($document, $sectionPath.'.images', [...array_values((array) ($section['images'] ?? [])), '']);
        $this->repository->saveDraft($document);
    }

    /**
     * Add a link to one of the site's link lists (`links.{list}`, drawn by `<x-gadya-cms::links>`). It starts
     * with words and no address, and visitors only see it once it has somewhere to go.
     */
    public function addLink(string $list): void
    {
        $document = $this->repository->draft();
        $links = array_values((array) Arr::get($document, "links.{$list}", []));
        $links[] = ['label' => 'New link', 'url' => ''];

        Arr::set($document, "links.{$list}", $links);
        $this->repository->saveDraft($document);
    }

    public function removeLink(string $list, int $index): void
    {
        $document = $this->repository->draft();
        $links = array_values((array) Arr::get($document, "links.{$list}", []));

        if (! array_key_exists($index, $links)) {
            throw new InvalidArgumentException('That link is already gone.');
        }

        array_splice($links, $index, 1);

        Arr::set($document, "links.{$list}", $links);
        $this->repository->saveDraft($document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, array<string, mixed>>
     */
    private function itemsAt(array $document, string $sectionPath): array
    {
        $section = Arr::get($document, $sectionPath);

        if (! is_array($section) || ! isset($section['items'])) {
            throw new InvalidArgumentException("[{$sectionPath}] is not a section with items.");
        }

        return $section['items'];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, array<string, mixed>>
     */
    private function sectionsAt(array $document, string $pageSlug): array
    {
        $sections = Arr::get($document, "pages.{$pageSlug}.sections");

        if (! is_array($sections)) {
            throw new InvalidArgumentException("[{$pageSlug}] is not an editable page.");
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writeItems(array $document, string $sectionPath, array $items): void
    {
        Arr::set($document, $sectionPath.'.items', $items);
        $this->repository->saveDraft($document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function writeSections(array $document, string $pageSlug, array $sections): void
    {
        Arr::set($document, "pages.{$pageSlug}.sections", $sections);
        $this->repository->saveDraft($document);
    }

    /**
     * @param  list<int>  $order
     */
    private function assertIsPermutation(array $order, int $expectedCount): void
    {
        $sorted = $order;
        sort($sorted);

        if ($sorted !== range(0, $expectedCount - 1)) {
            throw new InvalidArgumentException('The supplied order is not a valid permutation.');
        }
    }
}
