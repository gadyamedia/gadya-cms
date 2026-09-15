<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Support\Arr;
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

        if (! Arr::has($document, $path)) {
            throw new RuntimeException("The path [{$path}] does not exist.");
        }

        if (! is_scalar(Arr::get($document, $path))) {
            throw new RuntimeException("The path [{$path}] does not hold a scalar value.");
        }

        Arr::set($document, $path, $value);

        $this->repository->saveDraft($document);
    }
}
