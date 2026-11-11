import { onBind } from './bind.js';
import { refresh } from './refresh.js';
import { changeStructure, tell } from './structure.js';

onBind('[data-cms-add-item]', (button) => {
    button.addEventListener('click', () => changeStructure({
        operation: 'add-item',
        section_path: button.dataset.cmsAddItem,
        item: { title: 'New item' },
    }).then(() => refresh()).catch((failure) => tell(failure.message)));
});

onBind('[data-cms-remove-item]', (button) => {
    button.addEventListener('click', () => {
        if (!window.confirm('Remove this item?')) {
            return;
        }

        changeStructure({
            operation: 'remove-item',
            section_path: button.dataset.cmsRemoveItem,
            index: Number(button.dataset.cmsItemIndex),
        }).then(() => refresh()).catch((failure) => tell(failure.message));
    });
});
