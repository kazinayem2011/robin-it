import React, { createContext, useContext } from 'react';
import { EyeOff } from 'lucide-react';

/**
 * Categories the menu leaves out because nothing is on them yet.
 *
 * The mega menu hides a category with no products anywhere beneath it, so a
 * customer is never sent to "No products found". The tree did not say so, and
 * a category the shop had just made looked as if the menu were broken.
 * Provided once by the Categories page; read by each card and chip.
 */
export const EmptyCategoriesContext = createContext(new Set());

/**
 * @param {number}  id       the category
 * @param {boolean} compact  the icon alone, for a level-3 chip
 */
export default function NotInMenuTag({ id, compact = false }) {
    const empty = useContext(EmptyCategoriesContext);

    if (!empty.has(id)) return null;

    const why =
        'Not shown in the menu yet: no products in it or below it. It appears as soon as one is added.';

    return (
        <span
            className={`admin-cat-not-in-menu${compact ? ' is-compact' : ''}`}
            title={why}
            aria-label={why}
        >
            <EyeOff size={12} aria-hidden="true" />
            {!compact && <span>Not in menu — no products yet</span>}
        </span>
    );
}
