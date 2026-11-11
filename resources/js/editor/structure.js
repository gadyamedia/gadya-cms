import { endpoint, token } from './endpoints.js';

/*
 * A change to the shape of the draft - an item, a section, a photo slot, a
 * link - sent to the structure endpoint. Throws with the server's words, so
 * the client is told why when it cannot be done.
 */
export const changeStructure = async (payload) => {
    const response = await fetch(endpoint('structure'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token(),
        },
        body: JSON.stringify(payload),
    }).catch(() => null);

    if (!response || !response.ok) {
        const { message } = (await response?.json().catch(() => ({}))) ?? {};

        window.dispatchEvent(new CustomEvent('cms:save-failed', { detail: { payload } }));

        throw new Error(message ?? 'That change could not be saved. Check your connection and try again.');
    }
};

/* Say something on the toolbar, where the client is already looking. */
export const tell = (message) => {
    const status = document.querySelector('[data-cms-status]');

    if (status) {
        status.textContent = message;
    }
};
