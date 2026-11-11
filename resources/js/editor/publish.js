import { hasFailedSaves, retryFailed, whenSaved } from './save-queue.js';
import { tell } from './structure.js';

/*
 * Publish, Publish anyway and Exit editing first let every save land, so
 * the words typed just before the click are part of it, then send the form.
 *
 * The form is sent with the native submit(), not requestSubmit(): with no
 * save waiting, the wait ends while the browser is still delivering this
 * same submit event, and a requestSubmit() then is silently ignored. That
 * is why Publish "did nothing" unless a save happened to be in flight.
 */
const sendAfterSaves = (form, { confirmFirst = null, whenFailed }) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (form.dataset.cmsSending === 'true') {
            return;
        }

        if (confirmFirst && !window.confirm(confirmFirst)) {
            return;
        }

        const button = form.querySelector('button[type="submit"]');
        form.dataset.cmsSending = 'true';

        if (button) {
            button.disabled = true;
        }

        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }

        await whenSaved();

        /* A save that failed earlier is tried once more before giving up on it. */
        if (hasFailedSaves()) {
            await retryFailed();
        }

        if (hasFailedSaves() && !whenFailed()) {
            form.dataset.cmsSending = 'false';

            if (button) {
                button.disabled = false;
            }

            return;
        }

        HTMLFormElement.prototype.submit.call(form);
    });
};

document.querySelectorAll('[data-cms-publish]').forEach((form) => sendAfterSaves(form, {
    /* "Publish anyway" says whose changes go live with it before it does. */
    confirmFirst: form.dataset.cmsConfirm ?? null,
    whenFailed: () => {
        tell('Not published: some changes still did not save. Check your connection, then publish again.');

        return false;
    },
}));

document.querySelectorAll('[data-cms-exit]').forEach((form) => sendAfterSaves(form, {
    whenFailed: () => window.confirm('Some changes did not save. Leave the editor anyway?'),
}));
