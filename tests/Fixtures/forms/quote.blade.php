<section>
    <h2>Get a quote</h2>
    <form method="POST" action="{{ route('gadya-cms.forms.store', 'quote') }}" class="quote-form">
        @cmsForm('quote')
        <label for="q-name">Your name</label>
        <input id="q-name" name="name" required>

        <label>Email address <input type="email" name="email" required></label>

        <label for="q-job">What do you need?</label>
        <select id="q-job" name="job">
            <option value="">Choose one</option>
            <option value="repair">A repair</option>
            <option value="install">A new install</option>
        </select>

        <fieldset>
            <legend>When?</legend>
            <input type="radio" id="q-soon" name="when" value="soon"><label for="q-soon">As soon as possible</label>
            <input type="radio" id="q-later" name="when" value="later"><label for="q-later">In a month or so</label>
        </fieldset>

        <textarea name="details" placeholder="Tell us about the job"></textarea>
        <input type="checkbox" name="rooms[]" value="kitchen" id="q-kitchen"><label for="q-kitchen">Kitchen</label>
        <input type="checkbox" name="rooms[]" value="bath" id="q-bath"><label for="q-bath">Bathroom</label>
        <button type="submit">Send</button>
    </form>
</section>
