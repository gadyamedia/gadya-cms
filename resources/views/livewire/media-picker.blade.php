<div class="gadya-cms-picker">
    <div class="gadya-cms-picker__bar">
        <input class="gadya-cms-input" type="search" wire:model.live.debounce.300ms="search" placeholder="Search photos">
        <label class="gadya-cms-button cursor-pointer">
            Upload
            <input class="sr-only" type="file" wire:model="upload" accept="image/*">
        </label>
    </div>

    <p class="gadya-cms-note" wire:loading wire:target="upload,store">Uploading&hellip;</p>
    @error('upload')<p class="gadya-cms-error">{{ $message }}</p>@enderror
    @error('targetPath')<p class="gadya-cms-error">{{ $message }}</p>@enderror

    <ul class="gadya-cms-picker__grid">
        @foreach ($items as $item)
            <li wire:key="gadya-media-{{ $item->id }}">
                <button class="gadya-cms-picker__item" type="button" wire:click="select({{ $item->id }})" @disabled(! $item->isReady())>
                    <img src="@siteThumbnail($item->filename)" alt="{{ $item->alt_text ?? $item->original_name }}" loading="lazy">
                    <span>{{ $item->original_name }}</span>
                </button>
            </li>
        @endforeach
    </ul>

    {{ $items->links() }}
</div>
