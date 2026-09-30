{{--
    The form as a visitor would see it, in a frame of its own.

    It must not be part of this page: its questions are real inputs, and
    the ones marked required are empty, so the browser would refuse to
    send the admin's own form ("Save changes" doing nothing at all, with
    no message, because the empty input is on a hidden tab). In a
    sandboxed frame it keeps its own script and stays interactive - rules
    show and hide as it is answered - but takes no part in this form.
--}}
<div
    x-data="{ height: 320 }"
    x-on:message.window="if ($refs.frame && $event.source === $refs.frame.contentWindow && Number.isFinite($event.data?.gadyaFormPreviewHeight)) height = Math.max(160, Math.ceil($event.data.gadyaFormPreviewHeight))"
    class="gadya-form-preview"
>
    <iframe
        x-ref="frame"
        title="Preview of the form"
        sandbox="allow-scripts"
        loading="lazy"
        srcdoc="{{ $document }}"
        x-bind:style="'width: 100%; border: 0; height: ' + height + 'px'"
    ></iframe>
</div>
