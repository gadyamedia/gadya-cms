@php
    $editor = app(\Gadya\Cms\Editor\EditContext::class);
    $url = $editor->isEnabled()
        ? rescue(fn () => \Gadya\Cms\Filament\Pages\OpeningHoursSettings::getUrl(panel: (string) config('gadya-cms.panel', 'admin')), null, report: false)
        : null;
@endphp
@include('gadya-cms::editor.admin-link', ['url' => $url, 'label' => 'Change the opening hours in the admin'])
