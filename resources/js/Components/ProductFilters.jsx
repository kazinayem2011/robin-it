import React, { useEffect, useMemo, useRef, useState } from 'react';
import { SlidersHorizontal, X, Search, ChevronDown } from 'lucide-react';
import { formatBdt } from '../utils/formatters';
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
    /*
     * What the listing is sorted by, so a category link can carry it. Not used
     * for anything the sidebar draws.
     */
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

    /*
     * The slider runs from nothing to the dearest thing on the shelf, which is
     * how the trade draws it — Star Tech's laptop slider is 0 to 818,000, not
     * 34,000 to 818,000. Starting at the cheapest would make the left handle's
     * resting place mean "no minimum" and "the minimum there is" at once, and a
     * shelf where everything costs the same would have no track to drag along.
     *
     * It replaced a line of text reading "৳3,500 – ৳3,500 available", which is
     * what that shelf's bounds honestly were and no help to anybody.
     */
    const priceCeiling = Math.ceil(facets?.max_price ?? 0);

    /*
     * A hundred steps across whatever the shelf spans, so dragging feels the
     * same on a ৳2,000 cable shelf as on a ৳800,000 laptop one, and rounded so
     * the number under the handle is one a person would say out loud.
     */
    const priceStep = Math.max(1, Math.round(priceCeiling / 100));

    // An untouched handle rests at its end of the track: no minimum, no maximum.
    const clampPrice = (raw, fallback) => {
        const value = Number(raw);

        return raw === '' || Number.isNaN(value)
            ? fallback
            : Math.min(Math.max(value, 0), priceCeiling);
    };

    const sliderLow = clampPrice(minPrice, 0);
    const sliderHigh = clampPrice(maxPrice, priceCeiling);

    // Long lists get a search box. Short ones do not need one and a box over
    // four options is just clutter.
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

    /*
     * The questions this shelf asks — Wi-Fi Standard, Panel Type, RAM. They
     * come from the server with the category, so the sidebar draws whatever a
     * category declares and needs no code when a new one is defined.
     */
    const attributeFacets = useMemo(
        () => facets?.attributes ?? [],
        [facets?.attributes],
    );

    const chosenAttributes = useMemo(
        () => value.attributes ?? {},
        [value.attributes],
    );

    const toggleAttribute = (attributeSlug, valueSlug) => {
        const current = chosenAttributes[attributeSlug] ?? [];
        const next = current.includes(valueSlug)
            ? current.filter((v) => v !== valueSlug)
            : [...current, valueSlug];

        const merged = { ...chosenAttributes };

        if (next.length) {
            merged[attributeSlug] = next;
        } else {
            // Dropped rather than left empty, so the address does not collect
            // a trail of questions nobody answered.
            delete merged[attributeSlug];
        }

        onChange?.({
            attributes: Object.keys(merged).length ? merged : undefined,
        });
    };

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
        (value.on_sale ? 1 : 0) +
        Object.values(chosenAttributes).reduce(
            (n, picked) => n + picked.length,
            0,
        );

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
            attributes: undefined,
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
                            {priceCeiling > 0 && (
                                <div className="plp-price-slider">
                                    <span className="plp-price-track" />
                                    <span
                                        className="plp-price-track-fill"
                                        style={{
                                            left: `${(sliderLow / priceCeiling) * 100}%`,
                                            right: `${100 - (sliderHigh / priceCeiling) * 100}%`,
                                        }}
                                    />

                                    {/*
                                        Two overlaid range inputs rather than a
                                        library. They are draggable, and they
                                        are also arrow-keyable and readable to a
                                        screen reader without any of that being
                                        written — which a pair of divs would
                                        have had to earn back by hand.
                                    */}
                                    <input
                                        type="range"
                                        min="0"
                                        max={priceCeiling}
                                        step={priceStep}
                                        value={sliderLow}
                                        onChange={(event) => {
                                            const next = Number(
                                                event.target.value,
                                            );
                                            setMinPrice(
                                                String(
                                                    Math.min(next, sliderHigh),
                                                ),
                                            );
                                        }}
                                        aria-label="Minimum price"
                                        aria-valuetext={formatBdt(sliderLow)}
                                    />
                                    <input
                                        type="range"
                                        min="0"
                                        max={priceCeiling}
                                        step={priceStep}
                                        value={sliderHigh}
                                        onChange={(event) => {
                                            const next = Number(
                                                event.target.value,
                                            );
                                            setMaxPrice(
                                                String(
                                                    Math.max(next, sliderLow),
                                                ),
                                            );
                                        }}
                                        aria-label="Maximum price"
                                        aria-valuetext={formatBdt(sliderHigh)}
                                    />
                                </div>
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

                {/*
                 * Whatever the shelf declares, in the order it declares it.
                 * A value nothing matches is not offered — the server drops
                 * it — so every box here leads somewhere.
                 */}
                {!loading &&
                    attributeFacets.map((attribute) => {
                        const picked = chosenAttributes[attribute.slug] ?? [];
                        const section = `attr:${attribute.slug}`;

                        return (
                            <section
                                className="plp-filter-group"
                                key={attribute.slug}
                            >
                                <button
                                    type="button"
                                    className="plp-filter-legend"
                                    aria-expanded={!collapsed[section]}
                                    onClick={() => toggleSection(section)}
                                >
                                    <h4>
                                        {attribute.name}
                                        {picked.length > 0 && (
                                            <em className="plp-filter-tally">
                                                {picked.length}
                                            </em>
                                        )}
                                    </h4>
                                    <ChevronDown size={15} />
                                </button>

                                {!collapsed[section] && (
                                    <div className="plp-filter-options plp-filter-scroll">
                                        {attribute.values.map((option) => (
                                            <label
                                                key={option.slug}
                                                className="plp-filter-check"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="custom-checkbox-input"
                                                    checked={picked.includes(
                                                        option.slug,
                                                    )}
                                                    onChange={() =>
                                                        toggleAttribute(
                                                            attribute.slug,
                                                            option.slug,
                                                        )
                                                    }
                                                />
                                                <span>{option.label}</span>
                                                {/* How many it leaves, so a
                                                    shopper can see a dead end
                                                    before clicking into it. */}
                                                <em className="plp-filter-count">
                                                    {option.count}
                                                </em>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </section>
                        );
                    })}

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
