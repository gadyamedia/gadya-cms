import { onBind } from './bind.js';
import { refresh } from './refresh.js';

onBind('[data-cms-type="image"]', (element) => {
    element.addEventListener('click', (event) => {
        /* Words inside the photo's area are typed into; only the area itself opens the photo library. */
        if (event.target.closest('[data-cms-path]') !== element) {
            return;
        }

        event.preventDefault();

        window.dispatchEvent(new CustomEvent('cms:open-media-picker', {
            detail: { path: element.dataset.cmsPath },
        }));
    });
});

window.addEventListener('cms:open-media-picker', () => {
    document.querySelector('[data-cms-media-dialog]')?.showModal();
});

window.addEventListener('cms:image-selected', () => {
    document.querySelector('[data-cms-media-dialog]')?.close();
    refresh();
});
