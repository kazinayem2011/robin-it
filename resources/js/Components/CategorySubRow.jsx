import React from 'react';
import { Link } from '@inertiajs/react';
import { ROUTES } from '../constants/endpoints';

/**
 * The shelves one level down, in a line across the top of a category page.
 *
 * Star Tech's layout: on Office Equipment the row is Projector, Conference
 * System, PA System…; on Projector it is Projector's own shelves; on a shelf
 * with nothing below it there is no row at all. It used to list the makers
 * stocked anywhere beneath, which on a department put brands where the way
 * into its own shelves belonged.
 *
 * Each pill is a page with its own address. The sidebar's Brand checkboxes
 * still do the other job, choosing makers within this shelf.
 */
export const CategorySubRow = ({ categories = [] }) => {
    if (!Array.isArray(categories) || categories.length === 0) return null;

    return (
        <nav className="cat-sub-row" aria-label="Shop by category">
            {categories.map((category) => (
                <Link
                    key={category.id}
                    href={ROUTES.SHOP_CATEGORY(category.slug)}
                    className="cat-sub-pill"
                >
                    {category.name}
                </Link>
            ))}
        </nav>
    );
};

export default CategorySubRow;
