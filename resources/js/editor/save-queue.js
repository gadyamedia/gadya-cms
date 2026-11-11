import { endpoint, token } from './endpoints.js';

const pending = new Map();
const active = new Set();
const failed = new Set();
const waiters = [];

const announce = (message, failing = false) => {
    const status = document.querySelector('[data-cms-status]');

    if (status) {
        status.textContent = message;
        status.classList.toggle('gadya-cms-toolbar__status--failing', failing);
    }
};

/*
 * Leaving the page while a save is still on its way, or after one failed,
 * loses that edit without a word: the browser asks first.
 */
window.addEventListener('beforeunload', (event) => {
    if (pending.size > 0 || active.size > 0 || failed.size > 0) {
        event.preventDefault();
        event.returnValue = '';
    }
});

const settle = () => {
    if (active.size > 0 || pending.size > 0) {
        return;
    }

    announce(failed.size > 0 ? 'Some changes did not save. Check your connection, then click the field and try again.' : 'All changes saved', failed.size > 0);
    waiters.splice(0).forEach((resolve) => resolve());
};

const send = async (path, value) => {
    try {
        const response = await fetch(endpoint('inline'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token(),
            },
            body: JSON.stringify({ path, value }),
        });

        if (!response.ok) {
            throw new Error(`Save failed with status ${response.status}`);
        }

        const body = await response.json().catch(() => ({}));

        failed.delete(path);
        window.dispatchEvent(new CustomEvent('cms:saved', { detail: { path, html: body.html } }));
    } catch (error) {
        failed.add(path);
        window.dispatchEvent(new CustomEvent('cms:save-failed', { detail: { path, error } }));
    }
};

/*
 * Saves for one path are serialised: a second edit to the same field waits
 * for the first to land rather than racing it, so the last thing the client
 * typed is always the last thing written.
 */
const flush = async (path) => {
    if (active.has(path)) {
        return;
    }

    active.add(path);

    while (pending.has(path)) {
        const value = pending.get(path);
        pending.delete(path);
        announce('Saving…');
        await send(path, value);
    }

    active.delete(path);
    settle();
};

export const queueSave = (path, value) => {
    pending.set(path, value);
    flush(path);
};

/*
 * Resolves once every queued save has landed, so an action that reads the
 * draft (publishing) never runs ahead of the edit that was just made.
 */
export const whenSaved = () => {
    if (active.size === 0 && pending.size === 0) {
        return Promise.resolve();
    }

    return new Promise((resolve) => waiters.push(resolve));
};

export const hasFailedSaves = () => failed.size > 0;
