/*
 * The live editor talks to the routes the package registers on the public
 * site. The prefix is configurable server-side, so it is handed to the
 * browser on the toolbar rather than hard-coded here.
 */
const prefix = () => document.querySelector('[data-cms-toolbar]')?.dataset.cmsPrefix ?? 'cms';

export const endpoint = (path) => `/${prefix()}/${path}`;

export const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
