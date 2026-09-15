document.querySelectorAll('[data-cms-type="image"]').forEach((element) => {
    element.addEventListener('click', (event) => {
        event.preventDefault();

        window.dispatchEvent(new CustomEvent('cms:open-media-picker', {
            detail: { path: element.dataset.cmsPath },
        }));
    });
});

window.addEventListener('cms:open-media-picker', () => {
    document.querySelector('[data-cms-media-dialog]')?.showModal();
});

window.addEventListener('cms:image-selected', () => window.location.reload());
