<?php

namespace Gadya\Cms\Http\Requests;

use Gadya\Cms\Rules\EditablePath;
use Illuminate\Foundation\Http\FormRequest;

class InlineEditRequest extends FormRequest
{
    public function __construct(
        private readonly EditablePath $editablePath,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can((string) config('gadya-cms.gate', 'manage-content')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:255', $this->editablePath],
            'value' => ['present', 'string', 'max:5000'],
        ];
    }
}
