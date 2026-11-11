{{--
    The designs "+ Add a section here" offers, each drawn in miniature so
    the choice is visual rather than a list of names. One per page, drawn
    by the toolbar; `<x-gadya-cms::add-section>` opens it at its spot.
--}}
@php
    $document = app(\Gadya\Cms\Content\SiteContentRepository::class)->draft();
    $designs = app(\Gadya\Cms\Services\UpdateDraftStructure::class)->designsFor($document['pages'][$page]['type'] ?? null);
@endphp

@if ($designs !== [] && is_array($document['pages'][$page] ?? null))
    <dialog class="gadya-cms-designs" data-cms-section-picker data-cms-page="{{ $page }}" aria-labelledby="gadya-cms-designs-title">
        <form method="dialog">
            <h2 id="gadya-cms-designs-title">Pick a design for the new section</h2>
            <p>It starts with sample words to click and type over. Visitors only see it once your changes go live.</p>

            <div class="gadya-cms-designs__grid">
                @foreach ($designs as $type => $design)
                    <button type="button" class="gadya-cms-designs__option" data-cms-section-type="{{ $type }}">
                        <span class="gadya-cms-designs__preview gadya-cms-designs__preview--{{ $type }}" aria-hidden="true">
                            @for ($i = 0; $i < ($type === 'menu' ? 4 : 3); $i++)
                                <span></span>
                            @endfor
                        </span>
                        <strong>{{ $design['label'] ?? $type }}</strong>
                        <small>{{ $design['description'] ?? '' }}</small>
                    </button>
                @endforeach
            </div>

            <p class="gadya-cms-error" data-cms-section-error hidden></p>

            <div class="gadya-cms-designs__actions">
                <button class="gadya-cms-link" type="submit" value="cancel">Cancel</button>
            </div>
        </form>
    </dialog>
@endif
