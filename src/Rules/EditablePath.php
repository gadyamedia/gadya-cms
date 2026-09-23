<?php

namespace Gadya\Cms\Rules;

use Closure;
use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Services\UpdateDraftField;
use Illuminate\Contracts\Validation\ValidationRule;

class EditablePath implements ValidationRule
{
    public function __construct(
        private readonly EditableFields $fields,
        private readonly SiteContentRepository $repository,
        private readonly UpdateDraftField $action,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->fields->allows($value)) {
            $fail('That field cannot be edited.');

            return;
        }

        if (! $this->action->canWrite($this->repository->draft(), $value)) {
            $fail('That field does not exist on this page.');
        }
    }
}
