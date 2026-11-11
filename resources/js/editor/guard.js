/*
 * While editing, a click on anything editable is for editing. Words or a
 * photo inside a link - a card that links to its page - no longer follow
 * the link, which looked like the page reloading under the client.
 */
document.addEventListener(
    'click',
    (event) => {
        if (event.target.closest?.('[data-cms-path]') && event.target.closest('a[href]')) {
            event.preventDefault();
        }
    },
    true,
);
