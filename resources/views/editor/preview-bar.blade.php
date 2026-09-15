<div class="gadya-cms-toolbar gadya-cms-toolbar--preview" data-cms-preview-bar>
    <div class="gadya-cms-toolbar__inner">
        <p class="gadya-cms-toolbar__label">Previewing a draft</p>
        <p class="gadya-cms-toolbar__status">This is not what visitors see yet.</p>

        <div class="gadya-cms-toolbar__actions">
            <form method="POST" action="{{ route('gadya-cms.preview.stop') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="path" value="{{ request()->path() }}">
                <button class="gadya-cms-link" type="submit">Stop previewing</button>
            </form>
        </div>
    </div>
</div>
