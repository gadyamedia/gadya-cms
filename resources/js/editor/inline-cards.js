import { endpoint, token } from './endpoints.js';

const send = async (payload) => {
    const response = await fetch(endpoint('structure'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        window.dispatchEvent(new CustomEvent('cms:save-failed', { detail: { payload } }));

        return;
    }

    window.location.reload();
};

document.querySelectorAll('[data-cms-add-item]').forEach((button) => {
    button.addEventListener('click', () => send({
        operation: 'add-item',
        section_path: button.dataset.cmsAddItem,
        item: { title: 'New item' },
    }));
});

document.querySelectorAll('[data-cms-remove-item]').forEach((button) => {
    button.addEventListener('click', () => {
        if (!window.confirm('Remove this item?')) {
            return;
        }

        send({
            operation: 'remove-item',
            section_path: button.dataset.cmsRemoveItem,
            index: Number(button.dataset.cmsItemIndex),
        });
    });
});
