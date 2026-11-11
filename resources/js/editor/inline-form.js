import { onBind } from './bind.js';
import { refresh } from './refresh.js';
import { changeStructure, tell } from './structure.js';

/*
 * "Choose another form" on a Form section, for an editor: the section's
 * form is swapped in the draft, and the section is redrawn to show it.
 */
onBind('[data-cms-form-tools][data-cms-section]', (tools) => {
    const choice = tools.querySelector('[data-cms-form-choice]');
    const button = tools.querySelector('[data-cms-form-choose]');

    if (!choice || !button) {
        return;
    }

    button.addEventListener('click', async () => {
        button.disabled = true;

        try {
            await changeStructure({ operation: 'choose-form', section_path: tools.dataset.cmsSection, form: choice.value });
            await refresh();
        } catch (failure) {
            button.disabled = false;
            tell(failure.message);
        }
    });
});
