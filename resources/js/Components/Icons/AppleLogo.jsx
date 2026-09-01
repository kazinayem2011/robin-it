import React from 'react';

/**
 * The Apple mark.
 *
 * Lucide has no Apple icon and will not: its set is drawn shapes, not company
 * logos. The Mac rows wore ⌘ instead, which is a key on the keyboard rather
 * than the maker, and read as "shortcut" beside a list of machines.
 *
 * Drawn as a filled silhouette rather than a stroked outline, because that is
 * the only way the mark is recognisable — every other icon in the menu is a
 * 1.5px outline, so `fill: currentColor` and no stroke is what keeps this one
 * looking like it belongs in the same row.
 *
 * A shop showing the maker's mark against that maker's products is the
 * ordinary use of a logo, and the shape is not altered.
 *
 * The props mirror a lucide icon's — size and className — so this drops into
 * ICON_REGISTRY beside the rest and every caller stays the same.
 */
export default function AppleLogo({ size = 24, className = '', ...rest }) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="currentColor"
            stroke="none"
            className={className}
            aria-hidden="true"
            focusable="false"
            {...rest}
        >
            <path d="M16.36 12.72c-.02-2.2 1.8-3.26 1.88-3.31-1.02-1.5-2.62-1.7-3.18-1.72-1.35-.14-2.64.79-3.33.79-.69 0-1.75-.77-2.87-.75-1.48.02-2.84.86-3.6 2.18-1.53 2.66-.39 6.6 1.1 8.76.73 1.06 1.6 2.25 2.74 2.2 1.1-.04 1.51-.71 2.84-.71 1.32 0 1.7.71 2.86.69 1.18-.02 1.93-1.08 2.65-2.14.84-1.23 1.18-2.42 1.2-2.48-.03-.01-2.29-.88-2.31-3.5z" />
            <path d="M14.2 6.24c.6-.74 1.01-1.76.9-2.78-.87.04-1.93.58-2.56 1.31-.56.65-1.05 1.69-.92 2.69.97.07 1.96-.49 2.58-1.22z" />
        </svg>
    );
}
