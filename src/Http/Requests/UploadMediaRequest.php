<?php

namespace Gadya\Cms\Http\Requests;

use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The rules a new upload must satisfy. This request is never routed through
 * the HTTP kernel: the Filament and Livewire upload paths build its rules
 * directly for their own `validate()` call and authorize themselves, so
 * `authorize()` below is not part of the enforced authorization path.
 */
class UploadMediaRequest extends FormRequest
{
    public function __construct(
        private readonly ImageCapabilities $capabilities,
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
            'upload' => [
                'required',
                'file',
                'max:'.(int) config('gadya-cms.media.max_kilobytes', 15360),
                'mimes:'.implode(',', $this->capabilities->acceptedExtensions()),
            ],
        ];
    }
}
