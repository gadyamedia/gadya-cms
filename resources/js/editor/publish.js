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
