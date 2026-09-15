<?php

namespace Gadya\Cms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StructureRequest extends FormRequest
{
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
            'operation' => ['required', Rule::in(['add-item', 'remove-item', 'reorder-items'])],
            'section_path' => ['required', 'string', 'max:255', 'regex:/^pages\.[a-z0-9-]+\.sections\.\d+\z/'],
            'index' => ['required_if:operation,remove-item', 'integer', 'min:0'],
            'order' => ['required_if:operation,reorder-items', 'array'],
            'order.*' => ['integer', 'min:0'],
            'item' => ['required_if:operation,add-item', 'array'],
            'item.title' => ['nullable', 'string', 'max:200'],
            'item.text' => ['nullable', 'string', 'max:2000'],
            'item.image' => ['nullable', 'string', 'max:255'],
        ];
    }
}
