import { onBind } from './bind.js';
import { refresh } from './refresh.js';
import { changeStructure, tell } from './structure.js';

/*
 * The client's own links (`<x-gadya-cms::links>`): add one - it starts as
 * "New link" with no address and stays hidden from visitors until it has
 * somewhere to go - or remove one. Words and addresses are typed in place.
 */
onBind('[data-cms-link-add]', (button) => {
    button.addEventListener('click', () => {
        changeStructure({ operation: 'add-link', list: button.dataset.cmsLinkAdd })
            .then(() => refresh())
            .catch((failure) => tell(failure.message));
    });
});

onBind('[data-cms-link-remove]', (button) => {
    button.addEventListener('click', () => {
        if (!window.confirm('Remove this link?')) {
            return;
        }

        changeStructure({ operation: 'remove-link', list: button.dataset.cmsLinkRemove, index: Number(button.dataset.cmsLinkIndex) })
            .then(() => refresh())
            .catch((failure) => tell(failure.message));
    });
});
