import { bindAll } from './bind.js';

/*
 * After a change of shape the page is redrawn without leaving it. The page
 * is fetched again and each region (`<x-gadya-cms::region>`) whose markup
 * changed is swapped in, given its editors, and announced with
 * `cms:region-replaced` so the site's own scripts can wake what is in it.
 * A page with no regions, or one whose regions no longer line up, reloads
 * instead - and comes back to the same place rather than the top.
 */
const SCROLL_KEY = 'gadya-cms:scroll';
const served = new WeakMap();

const here = () => window.location.pathname + window.location.search;

export const remember = (root = document) => {
    root.querySelectorAll('[data-cms-region]').forEach((region) => served.set(region, region.innerHTML));

    if (root instanceof Element && root.matches('[data-cms-region]')) {
        served.set(root, root.innerHTML);
    }
};

const reloadInPlace = () => {
    try {
        sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ page: here(), y: window.scrollY }));
    } catch {
        /* Private browsing: the page simply reloads at the top. */
    }

    window.location.reload();
};

try {
    const saved = JSON.parse(sessionStorage.getItem(SCROLL_KEY) ?? 'null');
    sessionStorage.removeItem(SCROLL_KEY);

    if (saved?.page === here()) {
        const restore = () => window.scrollTo(0, saved.y);

        document.readyState === 'complete' ? restore() : window.addEventListener('load', restore, { once: true });
    }
} catch {
    /* Nothing kept, or storage is off. */
}

export const refresh = async ({ focus = null } = {}) => {
    const regions = [...document.querySelectorAll('[data-cms-region]')];

    if (regions.length === 0) {
        reloadInPlace();

        return;
    }

    const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store' }).catch(() => null);

    if (!response || !response.ok) {
        reloadInPlace();

        return;
    }

    const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
    const freshRegions = [...fresh.querySelectorAll('[data-cms-region]')];
    const counts = {};

    for (const region of regions) {
        if (!document.contains(region)) {
            continue;
        }

        const name = region.dataset.cmsRegion;
        const nth = (counts[name] = (counts[name] ?? -1) + 1);
        const replacement = freshRegions.filter((candidate) => candidate.dataset.cmsRegion === name)[nth];

        if (!replacement) {
            reloadInPlace();

            return;
        }

        if (served.get(region) === replacement.innerHTML) {
            continue;
        }

        const adopted = document.importNode(replacement, true);
        region.replaceWith(adopted);
        remember(adopted);
        bindAll(adopted);
        window.dispatchEvent(new CustomEvent('cms:region-replaced', { detail: { element: adopted, name } }));
    }

    if (focus) {
        document.querySelector(focus)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
};
