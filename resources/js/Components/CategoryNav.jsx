import React, { useState, useRef, useLayoutEffect, useCallback } from 'react';
import { Link } from '@inertiajs/react';
import BrandMark from './BrandMark';
import { getCategoryIcon, hasCategoryIcon } from '../utils/iconMap';
import { ROUTES } from '../constants/endpoints';
import { ChevronRight, MoreHorizontal } from 'lucide-react';

/**
 * The category bar and its dropdowns.
 *
 * This replaced a three-column mega panel with a promotional spotlight card in
 * each one. The panel was built when the shop had nine top-level categories and
 * a handful of subcategories; the catalogue now has fifteen and 237, and the
 * shape stopped working — a panel that shows one subcategory's children at a
 * time makes a shopper hunt through hover states for something the shops they
 * already use put in a plain list.
 *
 * ## Every row carries a mark, and not the same one
 *
 * Rows that name a *thing* get a drawn icon; rows that name a *brand* get a
 * lettermark coloured from the name, so ASUS and Antec are told apart at a
 * glance. Which of the two is decided by the name, never by depth: under Phone
 * the brands sit at the second level (Samsung, Redmi), under Component at the
 * third.
 *
 * ## Fifteen categories do not fit
 *
 * They fit on a wide monitor and run off the edge on a laptop, so what fits is
 * measured rather than assumed — a hardcoded "show the first ten" is wrong at
 * every width except the one it was written on. Whatever is left goes under
 * "More", so nothing becomes unreachable at any window size.
 */

/*
 * Matched on the name alone, never the node.
 *
 * Slugs carry their ancestry — a brand under Graphics Card is slugged
 * `component-graphics-card-asus` — so asking the resolver about the whole node
 * matches the *parent's* keyword and hands every one of nineteen brands the
 * same graphics-card icon. Which is exactly what it did: a column of identical
 * glyphs beside Colorful, INNO3D, MSI, ASUS and the rest.
 *
 * The name is the only part that describes this node and nothing above it.
 */
const markFor = (node, size) =>
    hasCategoryIcon(node.name) ? (
        getCategoryIcon(node.name, { size, className: 'cat-nav-icon' })
    ) : (
        <BrandMark name={node.name} logo={node.logo} size={size} />
    );

/** Roughly what the "More" control occupies, reserved before it exists. */
const MORE_WIDTH = 92;

export default function CategoryNav({ categories = [] }) {
    const [openCategory, setOpenCategory] = useState(null);
    const [openSub, setOpenSub] = useState(null);
    const [moreOpen, setMoreOpen] = useState(false);
    const [visibleCount, setVisibleCount] = useState(0);

    /*
     * Until this is true every category is rendered, because widths can only be
     * read off elements that exist — a hidden item has no width to measure.
     *
     * It is not a convenience. The categories arrive from an async fetch, so
     * the first render has none: initialising the count from `categories.length`
     * captured zero, and since a useState initial value is only ever used once,
     * the bar stayed at zero visible and swept all fifteen into "More".
     */
    const [measured, setMeasured] = useState(false);

    const navRef = useRef(null);
    // Natural widths, measured once. Re-measuring on resize is impossible once
    // items are hidden — they have no width to read — so they are remembered.
    const widthsRef = useRef([]);

    const close = () => {
        setOpenCategory(null);
        setOpenSub(null);
    };

    const recompute = useCallback(() => {
        const nav = navRef.current;

        if (!nav || widthsRef.current.length === 0) return;

        const available = nav.clientWidth;
        const widths = widthsRef.current;
        const total = widths.reduce((sum, w) => sum + w, 0);

        if (total <= available) {
            setVisibleCount(widths.length);

            return;
        }

        // Everything that fits alongside the "More" control.
        let used = MORE_WIDTH;
        let fits = 0;

        for (const width of widths) {
            if (used + width > available) break;
            used += width;
            fits++;
        }

        setVisibleCount(Math.max(1, fits));
    }, []);

    // Categories replaced: forget the old widths and render them all again so
    // the new set can be measured.
    useLayoutEffect(() => {
        widthsRef.current = [];
        setMeasured(false);
    }, [categories]);

    useLayoutEffect(() => {
        const nav = navRef.current;

        if (!nav || categories.length === 0 || measured) return;

        // Every item is on screen at this point, which is the only moment their
        // natural widths can be read.
        widthsRef.current = Array.from(
            nav.querySelectorAll('[data-cat-item]'),
        ).map((el) => Math.ceil(el.getBoundingClientRect().width));

        recompute();
        setMeasured(true);
    }, [categories, measured, recompute]);

    useLayoutEffect(() => {
        const nav = navRef.current;

        if (!nav || typeof ResizeObserver === 'undefined') return;

        const observer = new ResizeObserver(recompute);
        observer.observe(nav);

        return () => observer.disconnect();
    }, [recompute]);

    const renderCategory = (category, { inMore = false } = {}) => (
        <li
            key={category.id}
            data-cat-item={inMore ? undefined : ''}
            className={`${inMore ? 'cat-nav-moreitem' : 'cat-nav-item'} ${
                openCategory === category.id ? 'is-open' : ''
            }`}
            onMouseEnter={() => {
                setOpenCategory(category.id);
                setOpenSub(null);
            }}
            onMouseLeave={() => !inMore && close()}
        >
            {/*
             * An offer category holds no products of its own — the discounts
             * live on the products — so it links to the offers page rather than
             * to a category that would render empty.
             */}
            <Link
                href={
                    category.isOffer
                        ? ROUTES.OFFERS
                        : ROUTES.SHOP_CATEGORY(category.slug)
                }
                className={inMore ? 'cat-nav-sublink' : 'cat-nav-link'}
            >
                {markFor(category, 16)}
                <span>{category.name}</span>
                {category.badge && (
                    <em className="cat-nav-badge">{category.badge}</em>
                )}
                {inMore && category.subcategories?.length > 0 && (
                    <ChevronRight size={13} className="cat-nav-chevron" />
                )}
            </Link>

            {category.subcategories?.length > 0 && (
                <ul className={inMore ? 'cat-nav-brands' : 'cat-nav-drop'}>
                    {category.subcategories.map((sub) => (
                        <li
                            key={sub.id}
                            className={`cat-nav-subitem ${openSub === sub.id ? 'is-open' : ''}`}
                            onMouseEnter={() => setOpenSub(sub.id)}
                        >
                            <Link
                                href={ROUTES.SHOP_CATEGORY(sub.slug)}
                                className="cat-nav-sublink"
                            >
                                {markFor(sub, 15)}
                                <span>{sub.name}</span>
                                {!inMore && sub.children?.length > 0 && (
                                    <ChevronRight
                                        size={13}
                                        className="cat-nav-chevron"
                                    />
                                )}
                            </Link>

                            {/*
                             * Brands, in columns. Graphics Card carries
                             * nineteen and Keyboard twenty-eight: one column
                             * would run off the bottom of the screen.
                             *
                             * Not nested a third time inside "More" — a panel
                             * hanging off a panel hanging off a button is not
                             * navigable with a mouse.
                             */}
                            {!inMore && sub.children?.length > 0 && (
                                <ul className="cat-nav-brands">
                                    {sub.children.map((child) => (
                                        <li key={child.id}>
                                            <Link
                                                href={ROUTES.SHOP_CATEGORY(
                                                    child.slug,
                                                )}
                                                className="cat-nav-brandlink"
                                            >
                                                {markFor(child, 16)}
                                                <span>{child.name}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}

                    <li className="cat-nav-all">
                        <Link href={ROUTES.SHOP_CATEGORY(category.slug)}>
                            Show all {category.name}
                        </Link>
                    </li>
                </ul>
            )}
        </li>
    );

    // Before measuring, everything is rendered — briefly wider than the bar,
    // which the .is-measuring class hides for that one frame.
    const shown = measured ? categories.slice(0, visibleCount) : categories;
    const overflow = measured ? categories.slice(visibleCount) : [];

    return (
        <ul
            className={`cat-nav ${measured ? '' : 'is-measuring'}`}
            ref={navRef}
        >
            {shown.map((category) => renderCategory(category))}

            {overflow.length > 0 && (
                <li
                    className={`cat-nav-item cat-nav-more ${moreOpen ? 'is-open' : ''}`}
                    onMouseEnter={() => setMoreOpen(true)}
                    onMouseLeave={() => {
                        setMoreOpen(false);
                        close();
                    }}
                >
                    <button type="button" className="cat-nav-link">
                        <MoreHorizontal size={16} className="cat-nav-icon" />
                        <span>More</span>
                    </button>

                    <ul className="cat-nav-drop cat-nav-drop-right">
                        {overflow.map((category) =>
                            renderCategory(category, { inMore: true }),
                        )}
                    </ul>
                </li>
            )}
        </ul>
    );
}
