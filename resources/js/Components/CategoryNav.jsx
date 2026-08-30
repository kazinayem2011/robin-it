import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import BrandMark from './BrandMark';
import { getCategoryIcon, hasCategoryIcon } from '../utils/iconMap';
import { ROUTES } from '../constants/endpoints';
import { ChevronRight } from 'lucide-react';

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
 * So: a plain cascading list, which is what every hardware shop in this market
 * uses and what customers arrive already knowing how to drive. Hover a category
 * to get its subcategories, hover one of those to get its brands beside it.
 *
 * ## Every row carries a mark, and not the same one
 *
 * A menu where most rows wear an identical folder glyph has decoration, not
 * icons. Rows that name a *thing* get a drawn icon; rows that name a *brand*
 * get a lettermark in a colour derived from the name, so ASUS and Antec are
 * told apart at a glance.
 *
 * Which of the two is decided by the name, never by depth: under Phone the
 * brands sit at the second level (Samsung, Redmi), under Component at the
 * third.
 */

const markFor = (node, size) =>
    hasCategoryIcon(node) ? (
        getCategoryIcon(node, { size, className: 'cat-nav-icon' })
    ) : (
        <BrandMark name={node.name} logo={node.logo} size={size} />
    );

export default function CategoryNav({ categories = [] }) {
    const [openCategory, setOpenCategory] = useState(null);
    const [openSub, setOpenSub] = useState(null);

    const close = () => {
        setOpenCategory(null);
        setOpenSub(null);
    };

    return (
        <ul className="cat-nav">
            {categories.map((category) => (
                <li
                    key={category.id}
                    className={`cat-nav-item ${openCategory === category.id ? 'is-open' : ''}`}
                    onMouseEnter={() => {
                        setOpenCategory(category.id);
                        setOpenSub(null);
                    }}
                    onMouseLeave={close}
                >
                    {/*
                     * An offer category holds no products of its own — the
                     * discounts live on the products — so it links to the
                     * offers page rather than to a category that would render
                     * empty.
                     */}
                    <Link
                        href={
                            category.isOffer
                                ? ROUTES.OFFERS
                                : ROUTES.SHOP_CATEGORY(category.slug)
                        }
                        className="cat-nav-link"
                    >
                        {markFor(category, 16)}
                        <span>{category.name}</span>
                        {category.badge && (
                            <em className="cat-nav-badge">{category.badge}</em>
                        )}
                    </Link>

                    {category.subcategories?.length > 0 && (
                        <ul className="cat-nav-drop">
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
                                        {sub.children?.length > 0 && (
                                            <ChevronRight
                                                size={13}
                                                className="cat-nav-chevron"
                                            />
                                        )}
                                    </Link>

                                    {sub.children?.length > 0 && (
                                        /*
                                         * Brands, in columns. Graphics Card has
                                         * nineteen and Accessories > Keyboard
                                         * twenty-eight: as one column that runs
                                         * off the bottom of the screen, so the
                                         * list wraps into however many columns
                                         * it needs.
                                         */
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
                                                        <span>
                                                            {child.name}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}

                            <li className="cat-nav-all">
                                <Link
                                    href={ROUTES.SHOP_CATEGORY(category.slug)}
                                >
                                    Show all {category.name}
                                </Link>
                            </li>
                        </ul>
                    )}
                </li>
            ))}
        </ul>
    );
}
