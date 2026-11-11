/*
 * Every editor module says what it looks for and what to do with it, here,
 * rather than searching the page once when it loads. That way part of the
 * page redrawn after a change (see refresh.js) gets its editors too, and an
 * element is never set up twice.
 */
const binders = [];

export const onBind = (selector, bind) => {
    const bound = new WeakSet();

    binders.push((root) => {
        const own = root instanceof Element && root.matches(selector) ? [root] : [];

        [...own, ...root.querySelectorAll(selector)].forEach((element) => {
            if (bound.has(element)) {
                return;
            }

            bound.add(element);
            bind(element);
        });
    });
};

export const bindAll = (root = document) => binders.forEach((bind) => bind(root));
