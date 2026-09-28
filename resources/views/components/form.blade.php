@props([
    'form' => null,
    'title' => null,
    'intro' => null,
])

{{--
    <x-gadya-cms::form form="catering-order" />

    A form built under Content → Forms, wherever the template wants it.
    Renders nothing for a visitor when the form is not published.
--}}
{{ app(\Gadya\Cms\Forms\Builder\FormRenderer::class)->render($form instanceof \Gadya\Cms\Models\Form ? $form : (string) $form, ['title' => $title, 'intro' => $intro, 'class' => $attributes->get('class')]) }}
