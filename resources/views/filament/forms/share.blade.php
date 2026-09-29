@php
    $page = $form->publicUrl();
    $embed = $form->embedUrl();
    $snippet = '<iframe src="'.$embed.'" title="'.e($form->title).'" style="width:100%;border:0;min-height:480px" loading="lazy" data-gadya-form="'.$form->slug.'"></iframe>'
        ."\n".'<script>addEventListener("message",function(e){var d=e.data||{};if(!d.gadyaForm)return;document.querySelectorAll("iframe[data-gadya-form=\""+d.gadyaForm+"\"]").forEach(function(f){f.style.height=d.height+"px"})});</script>';
@endphp

<div class="gadya-share">
    @unless ($form->isLive())
        <p class="gadya-share__note"><strong>This form is a draft.</strong> None of these show anything until it is published.</p>
    @endunless

    <dl class="gadya-share__list">
        <dt>On a page of this site</dt>
        <dd>Edit the page, add a section, choose <strong>Form</strong> and pick "{{ $form->title }}". Or, in a longer text, write <code>[form:{{ $form->slug }}]</code> on a line of its own.</dd>

        @if ($page)
            <dt>Its own page</dt>
            <dd><a href="{{ $page }}" target="_blank" rel="noopener">{{ $page }}</a> - for a text, an email or a QR code.</dd>
        @endif

        <dt>On another website</dt>
        <dd>
            Paste this where the form should appear:
            <textarea readonly rows="5" class="gadya-share__code" onclick="this.select()">{{ $snippet }}</textarea>
        </dd>

        <dt>For a developer</dt>
        <dd>
            <code>&lt;x-gadya-cms::form form="{{ $form->slug }}" /&gt;</code>,
            <code>@@cmsFormEmbed('{{ $form->slug }}')</code>, or a button that opens it:
            <code>&lt;x-gadya-cms::form-popup form="{{ $form->slug }}" button="{{ $form->title }}" /&gt;</code>
        </dd>
    </dl>
</div>
