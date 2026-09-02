/**
 * The category bar, remembered between page loads.
 *
 * The menu is fetched after the app hydrates, from an empty array, so every
 * full page load painted an empty black bar and then filled it in. Measured:
 * ~0.9s locally and ~4.5s on the shared host, where the tree is 132 KB and the
 * request alone takes three seconds. That is the flash.
 *
 * Reading the last known tree synchronously means a refresh paints the bar it
 * had before, and the fetch behind it only ever corrects what is already
 * there. The first visit of all still waits — nothing can be shown before it
 * is known — but no subsequent one does.
 *
 * Deliberately localStorage rather than sessionStorage: the point is the
 * refresh and the second visit, and a category tree is not private. Nothing
 * here is trusted — a shape that does not look like the menu is discarded, so
 * a stale or hand-edited entry cannot render.
 */

const KEY = 'robinit.megamenu.v1';

/** Long enough to cover a browsing session, short enough that a renamed
 *  category is not wrong for a day. The fetch corrects it either way. */
const MAX_AGE_MS = 6 * 60 * 60 * 1000;

/** What the bar needs to draw a row. Anything less is not a usable menu. */
const looksLikeMenu = (value) =>
    Array.isArray(value) &&
    value.length > 0 &&
    value.every(
        (node) =>
            node &&
            typeof node === 'object' &&
            typeof node.name === 'string' &&
            typeof node.slug === 'string',
    );

/**
 * The last known menu, or an empty array.
 *
 * Never throws: storage can be unavailable (private windows, blocked site
 * data) and a header that cannot render is worse than one drawn a moment late.
 */
export const readCachedMenu = () => {
    try {
        const raw = window.localStorage.getItem(KEY);

        if (!raw) return [];

        const { at, tree } = JSON.parse(raw);

        if (!at || Date.now() - at > MAX_AGE_MS) return [];

        return looksLikeMenu(tree) ? tree : [];
    } catch {
        return [];
    }
};

export const writeCachedMenu = (tree) => {
    if (!looksLikeMenu(tree)) return;

    try {
        window.localStorage.setItem(
            KEY,
            JSON.stringify({ at: Date.now(), tree }),
        );
    } catch {
        // Quota, or storage switched off. The menu still works; it just will
        // not be instant next time.
    }
};

export default readCachedMenu;
