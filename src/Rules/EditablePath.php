<?php

namespace Gadya\Cms\Rules;

use Closure;
use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class EditablePath implements ValidationRule
{
    public function __construct(
        private readonly EditableFields $fields,
        private readonly SiteContentRepository $repository,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->fields->allows($value)) {
            $fail('That field cannot be edited.');

            return;
        }

        if (! Arr::has($this->repository->draft(), $value)) {
            $fail('That field does not exist on this page.');
        }
    }
}
