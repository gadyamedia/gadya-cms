/*
 * The live editor talks to the routes the package registers on the public
 * site. The prefix is configurable server-side, so it is handed to the
 * browser on the toolbar rather than hard-coded here. On a page in another
 * language the toolbar also names that language's base ("/es"), so every
 * save lands in the language being edited.
 */
const toolbar = () => document.querySelector('[data-cms-toolbar]');

const prefix = () => toolbar()?.dataset.cmsPrefix ?? 'cms';

const base = () => toolbar()?.dataset.cmsBase ?? '';

export const endpoint = (path) => `${base()}/${prefix()}/${path}`;

export const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
