@php
    $lock = app(\Gadya\Cms\Editor\EditingLock::class);
    $holder = $lock->holder();
    $heldByOther = $holder !== null && auth()->check() && ! $lock->isHeldBy(auth()->user());
    $panelUrl = \Filament\Facades\Filament::getPanel(config('gadya-cms.panel', 'admin'))->getUrl();
@endphp

<div class="gadya-cms-toolbar" data-cms-toolbar data-cms-prefix="{{ config('gadya-cms.editor.prefix', 'cms') }}">
    <div class="gadya-cms-toolbar__inner">
        <p class="gadya-cms-toolbar__label">Editing draft</p>
        <p class="gadya-cms-toolbar__status" data-cms-status aria-live="polite">All changes saved</p>

        @if ($heldByOther)
            <p class="gadya-cms-error">{{ $holder }} is editing right now</p>
        @endif

        <div class="gadya-cms-toolbar__actions">
            <a class="gadya-cms-link" href="{{ $panelUrl }}">Admin</a>

            @unless ($heldByOther)
                <form method="POST" action="{{ route('gadya-cms.publish') }}">
                    @csrf
                    <button class="gadya-cms-button" type="submit">Publish</button>
                </form>
            @endunless

            <form method="POST" action="{{ route('gadya-cms.edit-mode.disable') }}">
                @csrf
                @method('DELETE')
                <button class="gadya-cms-link" type="submit">Exit editing</button>
            </form>
        </div>
    </div>
</div>

<dialog class="gadya-cms-dialog" data-cms-media-dialog>
    <livewire:gadya-cms.media-picker />
</dialog>
