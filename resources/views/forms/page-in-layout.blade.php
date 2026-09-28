@extends($layout)

@section('content')
    <section class="cms-form-page">
        <h1 class="cms-form-page__heading">{{ $form->title }}</h1>
        @if (filled($form->description))
            <p class="cms-form-page__description">{{ $form->description }}</p>
        @endif
        {{ app(\Gadya\Cms\Forms\Builder\FormRenderer::class)->render($form) }}
    </section>
@endsection
