@csrf
<input type="hidden" name="_path" value="{{ request()->path() === '/' ? '/' : '/'.request()->path() }}">
@if ($honeypot !== '')
    {{-- Never shown to a person; a bot fills it in and gives itself away. --}}
    <div class="cms-form__trap" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
        <label for="cms-{{ $form }}-{{ $honeypot }}">Leave this empty</label>
        <input type="text" id="cms-{{ $form }}-{{ $honeypot }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
    </div>
@endif
