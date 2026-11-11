@php
    $lock = app(\Gadya\Cms\Editor\EditingLock::class);
    $holder = $lock->holder();
    $heldByOther = $holder !== null && auth()->check() && ! $lock->isHeldBy(auth()->user());
    $panelUrl = \Filament\Facades\Filament::getPanel(config('gadya-cms.panel', 'admin'))->getUrl();
    $locales = app(\Gadya\Cms\Localisation\Locales::class);
    $multilingual = $locales->isMultilingual();
    $editedPage = str_starts_with((string) app(\Gadya\Cms\Editor\EditContext::class)->basePath(), 'pages.')
        ? substr((string) app(\Gadya\Cms\Editor\EditContext::class)->basePath(), 6)
        : null;
    $publishNotice = session('status');
    $publishError = session('errors')?->first('publish');
@endphp

<div class="gadya-cms-toolbar" data-cms-toolbar data-cms-prefix="{{ config('gadya-cms.editor.prefix', 'cms') }}"@if ($multilingual) data-cms-base="{{ $locales->prefix($locales->current()) }}" data-cms-locale="{{ $locales->current() }}"@endif>
    <div class="gadya-cms-toolbar__inner">
        <p class="gadya-cms-toolbar__label">Editing draft
            @if ($multilingual)<span class="gadya-cms-toolbar__language">in {{ $locales->name($locales->current()) }}</span>@endif
        </p>
        <p class="gadya-cms-toolbar__status" data-cms-status aria-live="polite">All changes saved</p>

        @if ($multilingual)
            @include('gadya-cms::editor.languages')
        @endif

        @if ($heldByOther)
            <p class="gadya-cms-error">{{ $holder }} is editing right now</p>
        @endif

        {{-- Pressing Publish reloads the page: say what happened, or the client cannot tell it worked. --}}
        @if ($publishError)
            <p class="gadya-cms-error" role="alert">{{ $publishError }}</p>
        @elseif (is_string($publishNotice) && $publishNotice !== '')
            <p class="gadya-cms-toolbar__notice" role="status">{{ $publishNotice }}</p>
        @endif

        <div class="gadya-cms-toolbar__actions">
            <a class="gadya-cms-link" href="{{ $panelUrl }}">Admin</a>

            @if (auth()->user()?->can(\Gadya\Cms\Access\Abilities::gate(\Gadya\Cms\Access\Abilities::PUBLISH)))
                @if (! $heldByOther)
                    <form method="POST" action="{{ route('gadya-cms.publish') }}" data-cms-publish>
                        @csrf
                        <button class="gadya-cms-button" type="submit">Publish</button>
                    </form>
                @else
                    {{-- Never stuck behind someone else's editor, or a lock a closed laptop left behind. --}}
                    <form method="POST" action="{{ route('gadya-cms.edit-mode.take-over') }}">
                        @csrf
                        <button class="gadya-cms-link" type="submit">Take over editing</button>
                    </form>
                    <form method="POST" action="{{ route('gadya-cms.publish') }}" data-cms-publish
                        data-cms-confirm="{{ $holder }} is editing right now. Publishing puts everyone's changes live, {{ $holder }}'s included.">
                        @csrf
                        <input type="hidden" name="anyway" value="1">
                        <button class="gadya-cms-button" type="submit">Publish anyway</button>
                    </form>
                @endif
            @endif

            <form method="POST" action="{{ route('gadya-cms.edit-mode.disable') }}" data-cms-exit>
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

@if ($editedPage !== null && ! $heldByOther)
    @include('gadya-cms::editor.section-picker', ['page' => $editedPage])
@endif
