import React from 'react';
import { Link } from '@inertiajs/react';

/**
 * The makers stocked on this shelf, in a line across the top of it.
 *
 * How the trade lays out a category page, and the shortest route a shopper
 * has: most people arriving at Laptop already know whose laptop they want, and
 * asking them to open a filter panel to say so is a step for nothing.
 *
 * Each one is a page rather than a filter — `/shop/asus-all-laptop` — because
 * that is what it is: a shelf that stands for ASUS, with its own address a
 * customer can send to somebody. The Brand checkboxes in the sidebar still do
 * the other job, which is choosing two makers at once to compare.
 *
 * Draws nothing at all when the shelf has one maker or none. A row of one is
 * not a choice, and a row of none is a heading over empty space.
 */
export const CategoryBrandRow = ({ brands = [], activeSlug = null }) => {
    if (!Array.isArray(brands) || brands.length < 2) return null;

    return (
        <nav className="cat-brand-row" aria-label="Brands in this category">
            {brands.map((brand) => (
                <Link
                    key={brand.id}
                    href={`/shop/${brand.slug}`}
                    className={`cat-brand-pill${
                        brand.slug === activeSlug ? ' is-active' : ''
                    }`}
                    aria-current={
                        brand.slug === activeSlug ? 'page' : undefined
                    }
                >
                    {brand.name}
                </Link>
            ))}
        </nav>
    );
};

export default CategoryBrandRow;
