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
    <div class="gadya-cms-panel__actions">
        <button class="gadya-cms-button" type="button" data-cms-panel-save>Save</button>
        <button class="gadya-cms-link" type="button" data-cms-panel-cancel>Cancel</button>
    </div>
`;
document.body.append(panel);

const input = panel.querySelector('[data-cms-panel-input]');
let activeElement = null;

const close = () => {
    panel.hidden = true;
    activeElement = null;
};

panel.querySelector('[data-cms-panel-save]').addEventListener('click', () => {
    if (!activeElement) {
        return;
    }

    activeElement.innerText = input.value;
    queueSave(activeElement.dataset.cmsPath, input.value);
    close();
});

panel.querySelector('[data-cms-panel-cancel]').addEventListener('click', close);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) {
        close();
    }
});

document.querySelectorAll('[data-cms-type="multiline"]').forEach((element) => {
    element.addEventListener('click', () => {
        activeElement = element;
        input.value = element.innerText.trim();
        panel.hidden = false;
        input.focus();
    });
});
