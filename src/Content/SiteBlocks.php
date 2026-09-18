<?php

namespace Gadya\Cms\Content;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sections saved to be used again.
 *
 * A client who has built a good "what people say" strip wants it on four
 * pages without building it four times. A block is a copy of a section
 * kept under `blocks` in the site document; inserting one drops a fresh
 * copy into a page, which is then that page's to edit. Deliberately a
 * copy rather than a live reference: a client who changes a block on one
 * page almost never means to change it on the other three.
 */
class SiteBlocks
{
    public function __construct(private readonly SiteContentRepository $repository) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $blocks = $this->repository->draft()['blocks'] ?? [];

        return is_array($blocks) ? array_filter($blocks, 'is_array') : [];
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $key => $block) {
            $options[(string) $key] = (string) ($block['label'] ?? Str::headline((string) $key));
        }

        asort($options);

        return $options;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function save(string $label, array $section): string
    {
        $document = $this->repository->draft();
        $key = $this->availableKey(Str::slug($label) ?: 'block');

        unset($section['__block']);

        $document['blocks'][$key] = ['label' => $label, 'section' => $section];

        $this->repository->saveDraft($document);

        return $key;
    }

    /**
     * A fresh copy of a block's section, ready to drop into a page.
     *
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $block = $this->all()[$key] ?? null;

        if ($block === null || ! is_array($block['section'] ?? null)) {
            throw new RuntimeException('That block no longer exists.');
        }

        return $block['section'];
    }

    public function rename(string $key, string $label): void
    {
        $document = $this->repository->draft();

        if (! isset($document['blocks'][$key])) {
            throw new RuntimeException('That block no longer exists.');
        }

        $document['blocks'][$key]['label'] = $label;

        $this->repository->saveDraft($document);
    }

    public function forget(string $key): void
    {
        $document = $this->repository->draft();

        unset($document['blocks'][$key]);

        $this->repository->saveDraft($document);
    }

    private function availableKey(string $key): string
    {
        $blocks = $this->all();
        $candidate = $key;
        $suffix = 2;

        while (array_key_exists($candidate, $blocks)) {
            $candidate = "{$key}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
