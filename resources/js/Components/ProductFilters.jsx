import React, { useEffect, useMemo, useRef, useState } from 'react';
import { SlidersHorizontal, X } from 'lucide-react';
import { formatBdt } from '../utils/formatters';

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
    debounceMs = 400,
}) {
    const [minPrice, setMinPrice] = useState(value.min_price ?? '');
    const [maxPrice, setMaxPrice] = useState(value.max_price ?? '');
    const [open, setOpen] = useState(false);

    const brands = facets?.brands ?? [];
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

            <div className="plp-filters-body">
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

                <section className="plp-filter-group">
                    <h4>Price</h4>
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
                            onChange={(e) => setMinPrice(e.target.value)}
                            placeholder={
                                facets
                                    ? String(Math.floor(facets.min_price))
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
                            onChange={(e) => setMaxPrice(e.target.value)}
                            placeholder={
                                facets
                                    ? String(Math.ceil(facets.max_price))
                                    : 'Max'
                            }
                            aria-label="Maximum price"
                        />
                    </div>
                </section>

                {brands.length > 0 && (
                    <section className="plp-filter-group">
                        <h4>Brand</h4>
                        <div className="plp-filter-options">
                            {brands.map((brand) => (
                                <label
                                    key={brand.id}
                                    className="plp-filter-check"
                                >
                                    <input
                                        type="checkbox"
                                        checked={selectedBrands.includes(
                                            brand.id,
                                        )}
                                        onChange={() => toggleBrand(brand.id)}
                                    />
                                    <span>{brand.name}</span>
                                </label>
                            ))}
                        </div>
                    </section>
                )}

                <section className="plp-filter-group">
                    <h4>Availability</h4>
                    <label className="plp-filter-check">
                        <input
                            type="checkbox"
                            checked={Boolean(value.in_stock)}
                            onChange={(e) =>
                                onChange?.({
                                    in_stock: e.target.checked || undefined,
                                })
                            }
                        />
                        <span>In stock only</span>
                    </label>
                    <label className="plp-filter-check">
                        <input
                            type="checkbox"
                            checked={Boolean(value.on_sale)}
                            onChange={(e) =>
                                onChange?.({
                                    on_sale: e.target.checked || undefined,
                                })
                            }
                        />
                        <span>On sale</span>
                    </label>
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
