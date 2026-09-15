<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class UpdateDraftStructure
{
    public function __construct(private readonly SiteContentRepository $repository) {}

    /**
     * @return list<string>
     */
    public function sectionTypes(): array
    {
        /** @var list<string> $types */
        $types = config('gadya-cms.pages.section_types', []);

        return $types;
    }

    /**
     * @param  array<string, string>  $item
     */
    public function addItem(string $sectionPath, array $item): void
    {
        $document = $this->repository->draft();
        $items = $this->itemsAt($document, $sectionPath);
        $items[] = Arr::only($item, ['title', 'text', 'image']);

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

        $sections[] = $type === 'gallery'
            ? ['title' => $title, 'type' => $type, 'images' => []]
            : ['title' => $title, 'type' => $type, 'items' => []];

        $this->writeSections($document, $pageSlug, $sections);
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
