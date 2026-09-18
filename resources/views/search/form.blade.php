<form class="cms-search-form" method="GET" action="{{ route('gadya-cms.search') }}" role="search">
    <label class="cms-search-form__label" for="cms-search-input">{{ $label ?? 'Search this site' }}</label>
    <input
        class="cms-search-form__input"
        id="cms-search-input"
        type="search"
        name="q"
        value="{{ request()->query('q') }}"
        placeholder="{{ $placeholder ?? 'What are you looking for?' }}"
        autocomplete="off"
    >
    <button class="cms-search-form__button" type="submit">Search</button>
</form>
