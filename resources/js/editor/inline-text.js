import { queueSave } from './save-queue.js';

const originalValues = new WeakMap();

const commit = (element) => {
    const path = element.dataset.cmsPath;
    const value = element.innerText.trim();

    if (value === originalValues.get(element)) {
        return;
    }

    originalValues.set(element, value);
    queueSave(path, value);
};

const cancel = (element) => {
    element.innerText = originalValues.get(element) ?? element.innerText;
    element.blur();
};

const activate = (element) => {
    originalValues.set(element, element.innerText.trim());
    element.setAttribute('contenteditable', 'plaintext-only');
    element.setAttribute('spellcheck', 'true');

    if (element.tagName === 'A') {
        element.addEventListener('click', (event) => event.preventDefault());
    }

    element.addEventListener('blur', () => commit(element));
    element.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            cancel(element);
        }

        if (event.key === 'Enter' && element.dataset.cmsType === 'text') {
            event.preventDefault();
            element.blur();
        }
    });
};

document.querySelectorAll('[data-cms-type="text"]').forEach(activate);
