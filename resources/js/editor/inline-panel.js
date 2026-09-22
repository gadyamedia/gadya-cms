import { queueSave } from './save-queue.js';

/*
 * Longer copy gets a side panel rather than in-place editing: a paragraph
 * is much easier to write in a real textarea than in a contenteditable
 * block that is also trying to be the page.
 */
const panel = document.createElement('aside');
panel.className = 'gadya-cms-panel';
panel.hidden = true;
panel.innerHTML = `
    <label class="gadya-cms-panel__label">
        <span>Edit text</span>
        <textarea data-cms-panel-input></textarea>
    </label>
    <p class="gadya-cms-panel__hint" data-cms-panel-hint hidden>
        **Bold**, [a link](https://example.com), and a line starting with - for a bullet.
        A blank line starts a new paragraph.
    </p>
    <div class="gadya-cms-panel__actions">
        <button class="gadya-cms-button" type="button" data-cms-panel-save>Save</button>
        <button class="gadya-cms-link" type="button" data-cms-panel-cancel>Cancel</button>
    </div>
`;
document.body.append(panel);

const input = panel.querySelector('[data-cms-panel-input]');
const hint = panel.querySelector('[data-cms-panel-hint]');
let activeElement = null;

const close = () => {
    panel.hidden = true;
    activeElement = null;
};

panel.querySelector('[data-cms-panel-save]').addEventListener('click', () => {
    if (!activeElement) {
        return;
    }

    /*
     * A Markdown field keeps its source on the element and is redrawn from
     * the server's HTML once the save lands; plain text is shown as typed.
     */
    if (activeElement.dataset.cmsType === 'markdown') {
        activeElement.dataset.cmsValue = input.value;
    } else {
        activeElement.innerText = input.value;
    }

    queueSave(activeElement.dataset.cmsPath, input.value);
    close();
});

panel.querySelector('[data-cms-panel-cancel]').addEventListener('click', close);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) {
        close();
    }
});

window.addEventListener('cms:saved', (event) => {
    if (typeof event.detail?.html !== 'string') {
        return;
    }

    document.querySelectorAll('[data-cms-type="markdown"]').forEach((element) => {
        if (element.dataset.cmsPath === event.detail.path) {
            element.innerHTML = event.detail.html;
        }
    });
});

document.querySelectorAll('[data-cms-type="multiline"], [data-cms-type="markdown"]').forEach((element) => {
    element.addEventListener('click', (event) => {
        const markdown = element.dataset.cmsType === 'markdown';

        /* A link inside a Markdown field is for editing, not for following, while editing. */
        if (markdown) {
            event.preventDefault();
        }

        activeElement = element;
        input.value = markdown ? (element.dataset.cmsValue ?? '') : element.innerText.trim();
        hint.hidden = !markdown;
        panel.hidden = false;
        input.focus();
    });
});
