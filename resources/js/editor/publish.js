import { hasFailedSaves, whenSaved } from './save-queue.js';

/*
 * Clicking Publish straight after typing blurs the field, which starts its
 * save at the same moment the form posts - so the old draft went live and
 * the client had to press Publish a second time. The form now waits for
 * every save to land first, and refuses to publish over a failed one.
 */
document.querySelectorAll('[data-cms-publish]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        if (form.dataset.cmsSaved === 'true') {
            return;
        }

        event.preventDefault();

        /* "Publish anyway" says whose changes go live with it before it does. */
        if (form.dataset.cmsConfirm && !window.confirm(form.dataset.cmsConfirm)) {
            return;
        }

        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;

        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }

        await whenSaved();

        if (hasFailedSaves()) {
            button.disabled = false;

            return;
        }

        form.dataset.cmsSaved = 'true';
        form.requestSubmit();
    });
});

/*
 * "Exit editing" waits for the last edit to land too, so leaving the
 * editor straight after typing never drops what was just typed.
 */
document.querySelectorAll('[data-cms-exit]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        if (form.dataset.cmsSaved === 'true') {
            return;
        }

        event.preventDefault();

        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }

        await whenSaved();

        if (hasFailedSaves() && !window.confirm('Some changes did not save. Leave the editor anyway?')) {
            return;
        }

        form.dataset.cmsSaved = 'true';
        form.requestSubmit();
    });
});
