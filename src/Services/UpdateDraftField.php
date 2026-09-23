<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class UpdateDraftField
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly EditableFields $fields,
    ) {}

    public function handle(string $path, string $value): void
    {
        if (! $this->fields->allows($path)) {
            throw new RuntimeException("The path [{$path}] is not editable.");
        }

        $document = $this->repository->draft();

        if (! $this->canWrite($document, $path)) {
            throw new RuntimeException("The path [{$path}] does not exist.");
        }

        if (Arr::has($document, $path) && ! is_scalar(Arr::get($document, $path))) {
            throw new RuntimeException("The path [{$path}] does not hold a scalar value.");
        }

        Arr::set($document, $path, $value);

        $this->repository->saveDraft($document);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function canWrite(array $document, string $path): bool
    {
        return Arr::has($document, $path) || $this->canBeFilledIn($document, $path);
    }

    /**
     * A card added on the page starts with a title alone, so its
     * description and photo are not in the document yet. A field of an
     * item in a list is filled in when the item exists; a page never
     * gains a field it does not have, and a list never gains a slot.
     *
     * @param  array<string, mixed>  $document
     */
    private function canBeFilledIn(array $document, string $path): bool
    {
        $segments = explode('.', $path);

        if (count($segments) < 3 || is_numeric(end($segments)) || ! is_numeric($segments[count($segments) - 2])) {
            return false;
        }

        return is_array(Arr::get($document, Str::beforeLast($path, '.')));
    }
}
