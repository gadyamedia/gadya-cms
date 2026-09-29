import { endpoint, token } from './endpoints.js';

/*
 * "Choose another form" on a Form section, for an editor: the section's
 * form is swapped in the draft, and the page reloads to show it.
 */
document.querySelectorAll('[data-cms-form-tools][data-cms-section]').forEach((tools) => {
    const choice = tools.querySelector('[data-cms-form-choice]');
    const button = tools.querySelector('[data-cms-form-choose]');

    if (!choice || !button) {
        return;
    }

    button.addEventListener('click', async () => {
        button.disabled = true;

        const response = await fetch(endpoint('structure'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token(),
            },
            body: JSON.stringify({ operation: 'choose-form', section_path: tools.dataset.cmsSection, form: choice.value }),
        }).catch(() => null);

        if (!response || !response.ok) {
            button.disabled = false;
            window.dispatchEvent(new CustomEvent('cms:save-failed', { detail: { path: tools.dataset.cmsSection } }));

            return;
        }

        window.location.reload();
    });
});
