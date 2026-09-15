<?php

namespace Gadya\Cms\Content;

class MediaUsage
{
    /**
     * @var array<string, list<string>>|null
     */
    private ?array $cachedMap = null;

    private int $walkCount = 0;

    public function __construct(private readonly SiteContentRepository $repository) {}

    /**
     * Number of times the document has actually been walked by this
     * instance. Exposed so tests can prove the walk happens once per
     * instance regardless of how many filenames are looked up.
     */
    public function walkCount(): int
    {
        return $this->walkCount;
    }

    /**
     * @return list<string>
     */
    public function pagesUsing(string $filename): array
    {
        return $this->map()[$filename] ?? [];
    }

    /**
     * Builds a filename => list<page slug> map by walking the draft
     * document exactly once, no matter how many filenames are looked up.
     *
     * @return array<string, list<string>>
     */
    public function map(): array
    {
        if ($this->cachedMap === null) {
            $this->cachedMap = $this->buildMap();
        }

        return $this->cachedMap;
    }

    /**
     * Walks both the draft AND the published document. An image removed
     * from the draft but still referenced by the currently-published
     * document must still be reported as in use: deleting it would break
     * the live site until the next publish.
     *
     * @return array<string, list<string>>
     */
    private function buildMap(): array
    {
        $this->walkCount++;

        $map = [];

        foreach ([$this->repository->draft(), $this->repository->published()] as $document) {
            foreach ($document['pages'] ?? [] as $slug => $page) {
                if (! is_array($page)) {
                    continue;
                }

                foreach (array_unique($this->filenamesIn($page)) as $filename) {
                    if (! in_array((string) $slug, $map[$filename] ?? [], true)) {
                        $map[$filename][] = (string) $slug;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $haystack
     * @return list<string>
     */
    private function filenamesIn(array $haystack): array
    {
        $filenames = [];

        foreach ($haystack as $value) {
            if (is_array($value)) {
                array_push($filenames, ...$this->filenamesIn($value));

                continue;
            }

            if (is_string($value)) {
                $filenames[] = $value;
            }
        }

        return $filenames;
    }
}
