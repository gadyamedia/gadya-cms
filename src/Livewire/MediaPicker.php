<?php

namespace Gadya\Cms\Livewire;

use Gadya\Cms\Http\Requests\UploadMediaRequest;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Services\StoreMediaUpload;
use Gadya\Cms\Services\UpdateDraftField;
use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The picker the live editor opens when the client clicks an image on the
 * page. It writes the chosen filename straight into the draft document, so
 * the page reloads showing the new photo in place.
 */
class MediaPicker extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $targetPath = '';

    public string $search = '';

    public mixed $upload = null;

    public function booted(): void
    {
        Gate::authorize($this->gate());
    }

    public function mount(string $targetPath = ''): void
    {
        Gate::authorize($this->gate());

        $this->targetPath = $targetPath;
    }

    #[On('cms:open-media-picker')]
    public function open(string $path): void
    {
        Gate::authorize($this->gate());

        $this->targetPath = $path;
        $this->reset('search', 'upload');
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUpload(ImageCapabilities $capabilities, StoreMediaUpload $store): void
    {
        $this->store($capabilities, $store);
    }

    public function select(int $mediaId, UpdateDraftField $action): void
    {
        Gate::authorize($this->gate());

        $media = Media::query()->findOrFail($mediaId);

        if (! $media->isReady()) {
            $this->addError('targetPath', 'That image is still processing.');

            return;
        }

        try {
            $action->handle($this->targetPath, $media->filename);
        } catch (RuntimeException $exception) {
            $this->addError('targetPath', $exception->getMessage());

            return;
        }

        $this->dispatch('cms:image-selected', path: $this->targetPath, filename: $media->filename);
    }

    public function store(ImageCapabilities $capabilities, StoreMediaUpload $storeMediaUpload): void
    {
        Gate::authorize($this->gate());

        $this->validate((new UploadMediaRequest($capabilities))->rules());

        $storeMediaUpload->handle($this->upload, auth()->id());

        $this->reset('upload');
    }

    public function render(): View
    {
        return view('gadya-cms::livewire.media-picker', [
            'items' => $this->items(),
        ]);
    }

    private function gate(): string
    {
        return (string) config('gadya-cms.gate', 'manage-content');
    }

    /**
     * @return LengthAwarePaginator<int, Media>
     */
    private function items(): LengthAwarePaginator
    {
        return Media::query()
            ->when($this->search !== '', fn ($query) => $query->where('original_name', 'like', "%{$this->search}%"))
            ->latest('id')
            ->paginate(24);
    }
}
