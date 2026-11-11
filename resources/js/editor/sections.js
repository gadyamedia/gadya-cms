import { onBind } from './bind.js';
import { refresh } from './refresh.js';
import { changeStructure, tell } from './structure.js';

/*
 * Laying out a page from the page: "+ Add a section here" opens the design
 * picker the toolbar draws, and each section's bar moves it or removes it.
 * A gallery grows one empty photo slot at a time for the library to fill.
 */
const picker = document.querySelector('[data-cms-section-picker]');
let position = 0;

const redraw = (focusIndex = null) => refresh({
    focus: focusIndex === null ? null : `[data-cms-section-remove="${focusIndex}"]`,
});

if (picker) {
    const error = picker.querySelector('[data-cms-section-error]');

    picker.querySelectorAll('[data-cms-section-type]').forEach((option) => {
        option.addEventListener('click', async () => {
            option.disabled = true;

            try {
                await changeStructure({ operation: 'add-section', page: picker.dataset.cmsPage, type: option.dataset.cmsSectionType, position });
                picker.close();
                await redraw(position);
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
            } finally {
                option.disabled = false;
            }
        });
    });

    onBind('[data-cms-section-add]', (button) => {
        button.addEventListener('click', () => {
            position = Number(button.dataset.cmsSectionAdd);
            error.hidden = true;
            picker.showModal();
        });
    });
}

onBind('[data-cms-section-move]', (button) => {
    button.addEventListener('click', () => {
        const index = Number(button.dataset.cmsSectionIndex);
        const step = Number(button.dataset.cmsSectionMove);

        changeStructure({ operation: 'move-section', page: button.dataset.cmsPage, index, step })
            .then(() => redraw(index + step))
            .catch((failure) => tell(failure.message));
    });
});

onBind('[data-cms-section-remove]', (button) => {
    button.addEventListener('click', () => {
        if (!window.confirm('Remove this whole section? Visitors keep seeing it until your changes go live.')) {
            return;
        }

        changeStructure({ operation: 'remove-section', page: button.dataset.cmsPage, index: Number(button.dataset.cmsSectionRemove) })
            .then(() => redraw())
            .catch((failure) => tell(failure.message));
    });
});

onBind('[data-cms-gallery-add]', (button) => {
    button.addEventListener('click', () => {
        changeStructure({ operation: 'add-photo', section_path: button.dataset.cmsGalleryAdd })
            .then(() => redraw())
            .catch((failure) => tell(failure.message));
    });
});
