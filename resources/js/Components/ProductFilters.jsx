import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { SlidersHorizontal, X, Search, ChevronDown } from 'lucide-react';
import { formatBdt } from '../utils/formatters';
import { ROUTES } from '../constants/endpoints';
import { FilterFacetSkeleton } from './Skeleton';

/**
 * The shop's filter panel.
 *
 * Price bounds come from the catalogue rather than hardcoded brackets, and are
 * computed without the shopper's own price filter applied — a range whose ends
 * move as you drag it is unusable. Typing a price is debounced so the list is
 * not refetched on every keystroke.
 */
export default function ProductFilters({
    facets = null,
    value = {},
    onChange,
    categorySlug = null,
    // The offers page is already restricted to on-sale, so the box would be
    // a checkbox that does nothing.
    hideOnSale = false,
    /*
     * The facets have not arrived yet. Category and Brand are built entirely
     * from them, so without a placeholder those two sections simply are not
     * there and the sidebar collapses to a third of its height.
     */
    loading = false,
    /*
     * A refresh is in flight but the sidebar already has something to show.
     * It stays visible and stops taking clicks, rather than being replaced by
     * placeholders for content the shopper is looking at.
     */
    busy = false,
    debounceMs = 400,
}) {
    const [minPrice, setMinPrice] = useState(value.min_price ?? '');
    const [maxPrice, setMaxPrice] = useState(value.max_price ?? '');
    const [open, setOpen] = useState(false);

    // Long lists get a search box. Short ones do not need one and a box over
    // four options is just clutter.
    const [categoryQuery, setCategoryQuery] = useState('');
    const [brandQuery, setBrandQuery] = useState('');
    const [collapsed, setCollapsed] = useState({});
    const bodyRef = useRef(null);

    /*
     * pointer-events stops the mouse but not the keyboard — a link reached by
     * Tab is still activatable with Enter. `inert` takes the whole subtree out
     * of focus order and out of the accessibility tree for as long as it is
     * out of action. React 18 does not know the attribute, so it is set on the
     * node directly.
     */
    useEffect(() => {
        const node = bodyRef.current;

        if (!node) return;

        if (busy && !loading) {
            node.setAttribute('inert', '');
        } else {
            node.removeAttribute('inert');
        }
    }, [busy, loading]);

    const toggleSection = (key) =>
        setCollapsed((prev) => ({ ...prev, [key]: !prev[key] }));

    const categories = useMemo(
        () => facets?.categories ?? [],
        [facets?.categories],
    );

    /*
     * Matching a parent keeps its children, and matching a child keeps the
     * parent so the result is not an orphaned row with no idea where it sits.
     */
    const visibleCategories = useMemo(() => {
        const needle = categoryQuery.trim().toLowerCase();

        if (!needle) return categories;

        return categories
            .map((parent) => {
                const parentHit = parent.name.toLowerCase().includes(needle);
                const children = (parent.children ?? []).filter((child) =>
                    child.name.toLowerCase().includes(needle),
                );

                if (parentHit) return parent;
                if (children.length) return { ...parent, children };

                return null;
            })
            .filter(Boolean);
    }, [categories, categoryQuery]);

    /*
     * Only the branch being browsed is opened, plus anything a search matched.
     * Expanding all of them at once made the sidebar 5,800px tall — longer than
     * the results it is meant to filter.
     */
    const isExpanded = (parent) => {
        if (categoryQuery.trim()) return true;
        if (!categorySlug) return false;

        return (
            parent.slug === categorySlug ||
            (parent.children ?? []).some((c) => c.slug === categorySlug)
        );
    };

    const allBrands = useMemo(() => facets?.brands ?? [], [facets?.brands]);
    const brands = useMemo(() => {
        const needle = brandQuery.trim().toLowerCase();

        return needle
            ? allBrands.filter((b) => b.name.toLowerCase().includes(needle))
            : allBrands;
    }, [allBrands, brandQuery]);
    const selectedBrands = useMemo(
        () => value.brand_ids ?? [],
        [value.brand_ids],
    );

    // Keep the inputs in step when the caller clears everything.
    useEffect(() => {
        setMinPrice(value.min_price ?? '');
        setMaxPrice(value.max_price ?? '');
    }, [value.min_price, value.max_price]);

    // Debounce the price so a typed figure does not refetch per keystroke.
    // The callback lives in a ref: callers pass an inline arrow, and depending
    // on its identity would re-fire this effect forever.
    const onChangeRef = useRef(onChange);
    useEffect(() => {
        onChangeRef.current = onChange;
    });

    const committed = useRef({
        min: value.min_price ?? '',
        max: value.max_price ?? '',
    });

    useEffect(() => {
        const timer = setTimeout(() => {
            if (
                String(committed.current.min) === String(minPrice) &&
                String(committed.current.max) === String(maxPrice)
            ) {
                return;
            }

            committed.current = { min: minPrice, max: maxPrice };
            onChangeRef.current?.({
                min_price: minPrice === '' ? undefined : Number(minPrice),
                max_price: maxPrice === '' ? undefined : Number(maxPrice),
            });
        }, debounceMs);

        return () => clearTimeout(timer);
    }, [minPrice, maxPrice, debounceMs]);

    const toggleBrand = (id) => {
        const next = selectedBrands.includes(id)
            ? selectedBrands.filter((b) => b !== id)
            : [...selectedBrands, id];

        onChange?.({ brand_ids: next.length ? next : undefined });
    };

    const activeCount =
        (value.min_price ? 1 : 0) +
        (value.max_price ? 1 : 0) +
        selectedBrands.length +
        (value.in_stock ? 1 : 0) +
        (value.on_sale ? 1 : 0);

    const clearAll = () => {
        setMinPrice('');
        setMaxPrice('');
        committed.current = { min: '', max: '' };
        onChange?.({
            min_price: undefined,
            max_price: undefined,
            brand_ids: undefined,
            in_stock: undefined,
            on_sale: undefined,
        });
    };

    return (
        <aside className={`plp-filters ${open ? 'is-open' : ''}`}>
            <button
                type="button"
                className="plp-filters-toggle"
                onClick={() => setOpen((v) => !v)}
            >
                <SlidersHorizontal size={16} />
                Filters
                {activeCount > 0 && (
                    <span className="plp-filters-count">{activeCount}</span>
                )}
            </button>

            <div
                ref={bodyRef}
                className={`plp-filters-body${busy && !loading ? ' is-busy' : ''}`}
                aria-busy={busy || undefined}
            >
                <div className="plp-filters-head">
                    <h3>Filters</h3>
                    {activeCount > 0 && (
                        <button
                            type="button"
                            className="plp-filters-clear"
                            onClick={clearAll}
                        >
                            <X size={13} /> Clear all
                        </button>
                    )}
                </div>

                {loading && <FilterFacetSkeleton />}

                {!loading && categories.length > 0 && (
                    <section className="plp-filter-group">
                        <button
                            type="button"
                            className="plp-filter-legend"
                            aria-expanded={!collapsed.category}
                            onClick={() => toggleSection('category')}
                        >
                            <h4>Category</h4>
                            <ChevronDown size={15} />
                        </button>

                        {!collapsed.category && (
                            <>
                                {categories.length > 5 && (
                                    <div className="plp-filter-search">
                                        <Search size={13} />
                                        <input
                                            type="search"
                                            value={categoryQuery}
                                            onChange={(e) =>
                                                setCategoryQuery(e.target.value)
                                            }
                                            placeholder="Search categories"
                                            aria-label="Search categories"
                                        />
                                    </div>
                                )}

                                <ul className="plp-category-tree">
                                    <li>
                                        <Link
                                            href={ROUTES.SHOP}
                                            className={`plp-category-link${!categorySlug ? ' is-current' : ''}`}
                                        >
                                            All products
                                        </Link>
                                    </li>

                                    {visibleCategories.map((parent) => (
                                        <li key={parent.id}>
                                            <Link
                                                href={ROUTES.SHOP_CATEGORY(
                                                    parent.slug,
                                                )}
                                                className={`plp-category-link${categorySlug === parent.slug ? ' is-current' : ''}`}
                                            >
                                                <span>{parent.name}</span>
                                                <span className="plp-facet-count">
                                                    {parent.count}
                                                </span>
                                            </Link>

                                            {parent.children?.length > 0 &&
                                                isExpanded(parent) && (
                                                    <ul className="plp-category-children">
                                                        {parent.children.map(
                                                            (child) => (
                                                                <li
                                                                    key={
                                                                        child.id
                                                                    }
                                                                >
                                                                    <Link
                                                                        href={ROUTES.SHOP_CATEGORY(
                                                                            child.slug,
                                                                        )}
                                                                        className={`plp-category-link is-child${categorySlug === child.slug ? ' is-current' : ''}`}
                                                                    >
                                                                        <span>
                                                                            {
                                                                                child.name
                                                                            }
                                                                        </span>
                                                                        <span className="plp-facet-count">
                                                                            {
                                                                                child.count
                                                                            }
                                                                        </span>
                                                                    </Link>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                        </li>
                                    ))}

                                    {visibleCategories.length === 0 && (
                                        <li className="plp-filter-empty">
                                            No category matches “{categoryQuery}
                                            ”
                                        </li>
                                    )}
                                </ul>
                            </>
                        )}
                    </section>
                )}

                <section className="plp-filter-group">
                    <button
                        type="button"
                        className="plp-filter-legend"
                        aria-expanded={!collapsed.price}
                        onClick={() => toggleSection('price')}
                    >
                        <h4>Price</h4>
                        <ChevronDown size={15} />
                    </button>
                    {!collapsed.price && (
                        <>
                            {facets && facets.max_price > 0 && (
                                <p className="plp-filter-hint">
                                    {formatBdt(facets.min_price)} –{' '}
                                    {formatBdt(facets.max_price)} available
                                </p>
                            )}
                            <div className="plp-price-inputs">
                                <input
                                    type="number"
                                    min="0"
                                    inputMode="numeric"
                                    value={minPrice}
                                    onChange={(e) =>
                                        setMinPrice(e.target.value)
                                    }
                                    placeholder={
                                        facets
                                            ? String(
                                                  Math.floor(facets.min_price),
                                              )
                                            : 'Min'
                                    }
                                    aria-label="Minimum price"
                                />
                                <span>to</span>
                                <input
                                    type="number"
                                    min="0"
                                    inputMode="numeric"
                                    value={maxPrice}
                                    onChange={(e) =>
                                        setMaxPrice(e.target.value)
                                    }
                                    placeholder={
                                        facets
                                            ? String(
                                                  Math.ceil(facets.max_price),
                                              )
                                            : 'Max'
                                    }
                                    aria-label="Maximum price"
                                />
                            </div>
                        </>
                    )}
                </section>

                {!loading && allBrands.length > 0 && (
                    <section className="plp-filter-group">
                        <button
                            type="button"
                            className="plp-filter-legend"
                            aria-expanded={!collapsed.brand}
                            onClick={() => toggleSection('brand')}
                        >
                            <h4>Brand</h4>
                            <ChevronDown size={15} />
                        </button>

                        {!collapsed.brand && (
                            <>
                                {allBrands.length > 8 && (
                                    <div className="plp-filter-search">
                                        <Search size={13} />
                                        <input
                                            type="search"
                                            value={brandQuery}
                                            onChange={(e) =>
                                                setBrandQuery(e.target.value)
                                            }
                                            placeholder="Search brands"
                                            aria-label="Search brands"
                                        />
                                    </div>
                                )}
                                <div className="plp-filter-options plp-filter-scroll">
                                    {brands.map((brand) => (
                                        <label
                                            key={brand.id}
                                            className="plp-filter-check"
                                        >
                                            <input
                                                type="checkbox"
                                                className="custom-checkbox-input"
                                                checked={selectedBrands.includes(
                                                    brand.id,
                                                )}
                                                onChange={() =>
                                                    toggleBrand(brand.id)
                                                }
                                            />
                                            <span>{brand.name}</span>
                                        </label>
                                    ))}

                                    {brands.length === 0 && (
                                        <p className="plp-filter-empty">
                                            No brand matches “{brandQuery}”
                                        </p>
                                    )}
                                </div>
                            </>
                        )}
                    </section>
                )}

                <section className="plp-filter-group">
                    <button
                        type="button"
                        className="plp-filter-legend"
                        aria-expanded={!collapsed.availability}
                        onClick={() => toggleSection('availability')}
                    >
                        <h4>Availability</h4>
                        <ChevronDown size={15} />
                    </button>
                    <label className="plp-filter-check">
                        <input
                            type="checkbox"
                            className="custom-checkbox-input"
                            checked={Boolean(value.in_stock)}
                            onChange={(e) =>
                                onChange?.({
                                    in_stock: e.target.checked || undefined,
                                })
                            }
                        />
                        <span>In stock only</span>
                    </label>
                    {!hideOnSale && (
                        <label className="plp-filter-check">
                            <input
                                type="checkbox"
                                className="custom-checkbox-input"
                                checked={Boolean(value.on_sale)}
                                onChange={(e) =>
                                    onChange?.({
                                        on_sale: e.target.checked || undefined,
                                    })
                                }
                            />
                            <span>On sale</span>
                        </label>
                    )}
                </section>

                {facets && (
                    <p className="plp-filter-hint plp-filter-total">
                        {facets.total} product(s) match
                    </p>
                )}
            </div>
        </aside>
    );
}
