<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use RuntimeException;

class ManagePages
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
    ) {}

    public function create(string $slug, string $title, string $heading, string $type): void
    {
        $this->assertUsableSlug($slug);

        if (! in_array($type, $this->registry->creatableTypes(), true)) {
            throw new RuntimeException("[{$type}] is not a page type you can create.");
        }

        $document = $this->repository->draft();

        $document['pages'][$slug] = [
            'title' => $title,
            'type' => $type,
            'heading' => $heading,
            'description' => '',
            'status' => PageRegistry::STATUS_PUBLISHED,
            'sections' => [],
        ];

        $this->repository->saveDraft($document);
    }

    public function archive(string $slug): void
    {
        $this->setStatus($slug, PageRegistry::STATUS_ARCHIVED);
    }

    public function restore(string $slug): void
    {
        $this->setStatus($slug, PageRegistry::STATUS_PUBLISHED);
    }

    /**
     * Renaming a slug breaks every existing link and search result pointing at
     * the old address, so the old slug is recorded as a permanent redirect
     * rather than simply disappearing.
     */
    public function rename(string $slug, string $newSlug): void
    {
        if ($slug === $newSlug) {
            return;
        }

        $this->assertUsableSlug($newSlug);

        $document = $this->repository->draft();

        if (! isset($document['pages'][$slug])) {
            throw new RuntimeException("There is no page [{$slug}].");
        }

        $pages = [];

        foreach ($document['pages'] as $key => $page) {
            $pages[$key === $slug ? $newSlug : $key] = $page;
        }

        $document['pages'] = $pages;
        $document['redirects'] = $this->rewriteRedirects($document['redirects'] ?? [], $slug, $newSlug);
        $document['nav'] = $this->rewriteNav($document['nav'] ?? [], $slug, $newSlug);

        $this->repository->saveDraft($document);
    }

    public function setInNavigation(string $slug, bool $inNavigation, string $label): void
    {
        $document = $this->repository->draft();

        if (! isset($document['pages'][$slug])) {
            throw new RuntimeException("There is no page [{$slug}].");
        }

        $nav = array_values(array_filter(
            $document['nav'] ?? [],
            fn (array $item): bool => ($item['slug'] ?? null) !== $slug,
        ));

        if ($inNavigation) {
            $nav[] = ['label' => $label, 'slug' => $slug];
        }

        $document['nav'] = $nav;

        $this->repository->saveDraft($document);
    }

    private function setStatus(string $slug, string $status): void
    {
        $document = $this->repository->draft();

        if (! isset($document['pages'][$slug])) {
            throw new RuntimeException("There is no page [{$slug}].");
        }

        $document['pages'][$slug]['status'] = $status;

        $this->repository->saveDraft($document);
    }

    private function assertUsableSlug(string $slug): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new RuntimeException('A page address may only contain lowercase letters, numbers and single hyphens.');
        }

        if ($this->registry->isReserved($slug)) {
            throw new RuntimeException("[{$slug}] is reserved by the site and cannot be used.");
        }

        if (isset($this->repository->draft()['pages'][$slug])) {
            throw new RuntimeException("A page already uses the address [{$slug}].");
        }
    }

    /**
     * @param  array<string, string>  $redirects
     * @return array<string, string>
     */
    private function rewriteRedirects(array $redirects, string $slug, string $newSlug): array
    {
        foreach ($redirects as $from => $to) {
            if ($to === $slug) {
                $redirects[$from] = $newSlug;
            }
        }

        $redirects[$slug] = $newSlug;

        unset($redirects[$newSlug]);

        return $redirects;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nav
     * @return array<int, array<string, mixed>>
     */
    private function rewriteNav(array $nav, string $slug, string $newSlug): array
    {
        foreach ($nav as $index => $item) {
            if (($item['slug'] ?? null) === $slug) {
                $nav[$index]['slug'] = $newSlug;
            }
        }

        return $nav;
    }
}
