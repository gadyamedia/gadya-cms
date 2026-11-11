<?php

namespace Gadya\Cms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StructureRequest extends FormRequest
{
    /** Operations on one section or its items, addressed by `section_path`. */
    public const SECTION_OPERATIONS = ['add-item', 'remove-item', 'reorder-items', 'choose-form', 'add-photo'];

    /** Operations on a page's list of sections, addressed by `page`. */
    public const PAGE_OPERATIONS = ['add-section', 'move-section', 'remove-section'];

    /** Operations on a link list, addressed by `list`. */
    public const LINK_OPERATIONS = ['add-link', 'remove-link'];

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
            'operation' => ['required', Rule::in([...self::SECTION_OPERATIONS, ...self::PAGE_OPERATIONS, ...self::LINK_OPERATIONS])],
            'section_path' => [Rule::requiredIf(in_array($this->input('operation'), self::SECTION_OPERATIONS, true)), 'string', 'max:255', 'regex:/^pages\.[a-z0-9-]+\.sections\.\d+\z/'],
            'page' => [Rule::requiredIf(in_array($this->input('operation'), self::PAGE_OPERATIONS, true)), 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'list' => [Rule::requiredIf(in_array($this->input('operation'), self::LINK_OPERATIONS, true)), 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'index' => ['required_if:operation,remove-item,move-section,remove-section,remove-link', 'integer', 'min:0'],
            'position' => ['required_if:operation,add-section', 'integer', 'min:0'],
            'type' => ['required_if:operation,add-section', 'string', 'max:40'],
            'step' => ['required_if:operation,move-section', 'integer', Rule::in([-1, 1])],
            'order' => ['required_if:operation,reorder-items', 'array'],
            'order.*' => ['integer', 'min:0'],
            'item' => ['required_if:operation,add-item', 'array'],
            'item.title' => ['nullable', 'string', 'max:200'],
            'item.text' => ['nullable', 'string', 'max:2000'],
            'item.image' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'required_if:operation,choose-form', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
        ];
    }
}
