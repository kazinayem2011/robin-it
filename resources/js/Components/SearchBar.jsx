import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Search,
    X,
    SlidersHorizontal,
    Flame,
    Layers,
    Award,
    ArrowRight,
} from 'lucide-react';
import { productService } from '../services';
import { useMarqueeDuration } from '../hooks';
import siteConfig from '../constants/siteConfig';
import Select from './Select';
import { ROUTES } from '../constants/endpoints';
import { formatBdt } from '../utils/formatters';
import ProductImage from './ProductImage';

/**
 * Enhanced High-Conversion SearchBar component with multi-facet instant suggestions (SSOT)
 */
/**
 * The category tree used to be passed in for the scope dropdown, which the
 * header already draws as the mega menu directly below. It scopes by
 * availability now and needs no tree, so the header stops handing it one —
 * matching categories still reach the shopper as suggestions while they type,
 * which is where a name is worth showing because they asked for it.
 */
export const SearchBar = ({ onSearch }) => {
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedScope, setSelectedScope] = useState('all');
    const [suggestions, setSuggestions] = useState({
        products: [],
        categories: [],
        brands: [],
    });
    const [isSearching, setIsSearching] = useState(false);
    const [searchFocused, setSearchFocused] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(-1);
    const tagsRef = useRef(null);
    const { url } = usePage();

    /*
     * The dropdown follows the page.
     *
     * Ticking "In Stock" in the filter sidebar used to leave this saying
     * something else entirely, so the shop and its own search box disagreed
     * about what you were looking at — and searching from there would have
     * thrown the narrowing away. Read from the query string now rather than
     * the path, because that is where the scope lives.
     *
     * Only on a listing: elsewhere the choice is the shopper's and nothing
     * should quietly undo it.
     */
    useEffect(() => {
        const [rawPath, query = ''] = (url || '').split('?');
        const path = rawPath.replace(/\/$/, '');

        /*
         * Both, because the listing answers at both: shop.index and
         * products.index render the same screen, and the sidebar's own links
         * use /products — so recognising only /shop meant ticking "In Stock"
         * there left the box still saying "All Tech".
         */
        const onListing = ['/shop', '/products'].some(
            (root) => path === root || path.startsWith(`${root}/`),
        );

        if (!onListing) return;

        const params = new URLSearchParams(query);

        if (params.get('in_stock')) {
            setSelectedScope('in_stock');
        } else if (params.get('on_sale')) {
            setSelectedScope('on_sale');
        } else {
            setSelectedScope('all');
        }
    }, [url]);

    /*
     * What to search within.
     *
     * This used to be the category tree, which the header already draws twice
     * over: the mega menu below is fed the very same prop, and typing returns
     * matching categories as suggestions. A third copy of it, flattened into a
     * 116px box holding thirteen hundred names, was the least useful of the
     * three — and the only one that could not show a name long enough to read.
     *
     * These cut across the catalogue instead, which is the thing browsing
     * cannot do: "RTX 4090, but only what you can ship today". Both are
     * filters the listing already understands.
     */
    const searchIn = useMemo(
        () => [
            { value: 'all', label: 'All Tech' },
            { value: 'in_stock', label: 'In Stock' },
            { value: 'on_sale', label: 'On Offer' },
        ],
        [],
    );

    // Same speed as the announcement ticker, from the measured content width.
    useMarqueeDuration(tagsRef);
    const searchRef = useRef(null);

    // Debounced Live Multi-Facet Search Autocomplete (SSOT)
    useEffect(() => {
        if (!searchQuery.trim() || searchQuery.trim().length < 2) {
            setSuggestions({ products: [], categories: [], brands: [] });
            setSelectedIndex(-1);
            return;
        }

        const timer = setTimeout(() => {
            setIsSearching(true);
            productService
                .getSearchSuggestions(searchQuery)
                .then((data) => {
                    setSuggestions({
                        products: data?.products || [],
                        categories: data?.categories || [],
                        brands: data?.brands || [],
                    });
                })
                .catch(() => {
                    setSuggestions({
                        products: [],
                        categories: [],
                        brands: [],
                    });
                })
                .finally(() => {
                    setIsSearching(false);
                });
        }, 180);

        return () => clearTimeout(timer);
    }, [searchQuery]);

    // Handle outside click to close suggestions
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (searchRef.current && !searchRef.current.contains(e.target)) {
                setSearchFocused(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const totalResults = suggestions.products.length;

    // Keyboard Hotkey Navigation (↑ / ↓ / Enter / Esc)
    const handleKeyDown = (e) => {
        if (!searchFocused) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSelectedIndex((prev) =>
                prev < totalResults - 1 ? prev + 1 : 0,
            );
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSelectedIndex((prev) =>
                prev > 0 ? prev - 1 : totalResults - 1,
            );
        } else if (e.key === 'Escape') {
            setSearchFocused(false);
        } else if (e.key === 'Enter') {
            if (selectedIndex >= 0 && suggestions.products[selectedIndex]) {
                e.preventDefault();
                setSearchFocused(false);
                router.visit(
                    ROUTES.PRODUCT_DETAIL(
                        suggestions.products[selectedIndex].slug,
                    ),
                );
            } else {
                handleSubmit(e);
            }
        }
    };

    /*
     * Where a category and a term lead, together.
     *
     * The category is the route — `/shop/laptop`, not
     * `/shop?category_slug=laptops`, which the listing does not read as a
     * category at all and which fell through to its shelf-attribute filters,
     * asking for products whose spec "category_slug" is "laptops". No product
     * has one, so every category but "All Tech" used to return nothing.
     */
    const destination = (scope, term) => {
        const params = new URLSearchParams();
        const q = (term || '').trim();

        if (q) params.set('search', q);

        // The listing reads both from the query string, and validates them as
        // booleans — the same two the sidebar ticks.
        if (scope === 'in_stock') params.set('in_stock', '1');
        if (scope === 'on_sale') params.set('on_sale', '1');

        const query = params.toString();

        return query ? `${ROUTES.SHOP}?${query}` : ROUTES.SHOP;
    };

    /*
     * Choosing a scope goes there.
     *
     * It used to only set a variable: with an empty search box, picking one
     * did nothing at all, and neither did Enter or the button afterwards,
     * because submitting was guarded on there being a term. The shopper had
     * made a choice and the shop ignored it.
     *
     * Any term already typed comes along, so narrowing mid-search runs that
     * search inside the narrower set rather than starting over.
     */
    const chooseScope = (scope) => {
        setSelectedScope(scope);

        if (scope === selectedScope) return;

        setSearchFocused(false);
        router.visit(destination(scope, searchQuery));
    };

    const handleSubmit = (e) => {
        e?.preventDefault();
        setSearchFocused(false);

        if (onSearch) {
            onSearch(searchQuery, selectedScope);

            return;
        }

        // A term, a scope, or both. Only an empty box on "All Tech" has
        // nowhere to go.
        if (searchQuery.trim() || selectedScope !== 'all') {
            router.visit(destination(selectedScope, searchQuery));
        }
    };

    const hasResults =
        suggestions.products.length > 0 ||
        suggestions.categories.length > 0 ||
        suggestions.brands.length > 0;

    return (
        <div className="main-search-wrapper" ref={searchRef}>
            <form
                onSubmit={handleSubmit}
                className={`main-search-bar ${searchFocused ? 'search-focused' : ''}`}
                noValidate
            >
                {/*
                 * The icon is inside the control, not next to it.
                 *
                 * The strip reads as one thing — icon, label, chevron, a rule
                 * separating it from the search box — but only the label and
                 * chevron were the control. Clicking the icon, or the padding
                 * on either side of it, did nothing at all, which is the part
                 * of the strip the eye lands on first.
                 */}
                <div className="search-category-selector">
                    <Select
                        value={selectedScope}
                        onChange={(e) => chooseScope(e.target.value)}
                        options={searchIn}
                        aria-label="Search within"
                        icon={SlidersHorizontal}
                        className="search-category-select"
                    />
                </div>

                <input
                    type="text"
                    placeholder="Search by product, brand, processor, GPU (e.g. RTX 4090, i9 14900K)..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onFocus={() => setSearchFocused(true)}
                    onKeyDown={handleKeyDown}
                    className="search-text-input"
                    autoComplete="off"
                />

                {searchQuery && (
                    <button
                        type="button"
                        className="search-clear-btn"
                        onClick={() => {
                            setSearchQuery('');
                            setSuggestions({
                                products: [],
                                categories: [],
                                brands: [],
                            });
                        }}
                        aria-label="Clear search query"
                    >
                        <X size={15} />
                    </button>
                )}

                <button
                    type="submit"
                    className="search-submit-btn"
                    aria-label="Submit Search"
                >
                    <Search size={18} />
                </button>
            </form>

            {/* Live Trending Search Tags */}
            <div className="search-hot-tags">
                {/* Fixed, like the ticker's heading: it says what the row is,
                    so it should not have to be chased to be read. */}
                <span className="hot-tag-label">
                    <Flame size={12} className="text-primary" /> Hot:
                </span>
                <div className="header-marquee">
                    <div className="header-marquee-track" ref={tagsRef}>
                        {/*
                         * Two copies so the loop has no seam. Unlike the
                         * ticker these are real buttons, so the copy is taken
                         * out of the tab order as well as hidden from screen
                         * readers — otherwise every keyword is reachable twice
                         * and one of the two goes nowhere useful.
                         */}
                        {[false, true].map((isDuplicate) => (
                            <div
                                className="hot-tag-group"
                                key={isDuplicate ? 'copy' : 'original'}
                                aria-hidden={isDuplicate || undefined}
                            >
                                {siteConfig.trendingKeywords.map((kw) => (
                                    <button
                                        key={kw}
                                        type="button"
                                        tabIndex={isDuplicate ? -1 : undefined}
                                        onClick={() => {
                                            setSearchQuery(kw);
                                            setSearchFocused(true);
                                        }}
                                        className="hot-tag"
                                    >
                                        {kw}
                                    </button>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Live Autocomplete Results Flyout Dropdown */}
            {searchFocused && searchQuery.trim().length >= 2 && (
                <div className="search-results-flyout">
                    {isSearching ? (
                        <div className="search-loading-row">
                            <span className="mini-spinner"></span>
                            <span>Searching catalogue...</span>
                        </div>
                    ) : hasResults ? (
                        <div className="search-results-content">
                            {/* Matching Categories & Brands Quick Pills */}
                            {(suggestions.categories.length > 0 ||
                                suggestions.brands.length > 0) && (
                                <div className="search-facets-quickstrip">
                                    {suggestions.categories.length > 0 && (
                                        <div className="facet-group">
                                            <span className="facet-title">
                                                <Layers size={12} /> Categories:
                                            </span>
                                            {suggestions.categories.map(
                                                (cat) => (
                                                    <Link
                                                        key={cat.id}
                                                        href={ROUTES.SHOP_CATEGORY(
                                                            cat.slug,
                                                        )}
                                                        className="facet-pill"
                                                        onClick={() =>
                                                            setSearchFocused(
                                                                false,
                                                            )
                                                        }
                                                    >
                                                        {cat.name}
                                                    </Link>
                                                ),
                                            )}
                                        </div>
                                    )}
                                    {suggestions.brands.length > 0 && (
                                        <div className="facet-group">
                                            <span className="facet-title">
                                                <Award size={12} /> Brands:
                                            </span>
                                            {suggestions.brands.map((br) => (
                                                <Link
                                                    key={br.id}
                                                    /* brand_ids: ?brand=<slug>
                                                       returns an empty grid. */
                                                    href={`${ROUTES.SHOP}?brand_ids=${br.id}`}
                                                    className="facet-pill"
                                                    onClick={() =>
                                                        setSearchFocused(false)
                                                    }
                                                >
                                                    {br.name}
                                                </Link>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Matching Products List */}
                            {suggestions.products.length > 0 && (
                                <div className="search-results-list">
                                    <div className="search-section-label">
                                        Products Matching "{searchQuery}"
                                    </div>
                                    {suggestions.products.map((prod, idx) => (
                                        <Link
                                            key={prod.id}
                                            href={ROUTES.PRODUCT_DETAIL(
                                                prod.slug,
                                            )}
                                            className={`search-result-item ${selectedIndex === idx ? 'item-keyboard-active' : ''}`}
                                            onClick={() =>
                                                setSearchFocused(false)
                                            }
                                        >
                                            <ProductImage
                                                product={prod}
                                                alt={prod.name}
                                                className="search-item-thumb"
                                            />
                                            <div className="search-item-info">
                                                <h6 className="search-item-title">
                                                    {prod.name}
                                                </h6>
                                                <div className="search-item-meta">
                                                    {/* Was the shop's own
                                                        name when the product
                                                        had no brand. */}
                                                    {(prod.brand?.name ||
                                                        prod.brand) && (
                                                        <span className="search-item-brand">
                                                            {prod.brand?.name ||
                                                                prod.brand}
                                                        </span>
                                                    )}
                                                    <span className="search-item-price">
                                                        {formatBdt(
                                                            prod.discount_price ||
                                                                prod.price,
                                                        )}
                                                    </span>
                                                    {prod.discount_price && (
                                                        <span className="search-discount-badge">
                                                            SAVE{' '}
                                                            {formatBdt(
                                                                prod.price -
                                                                    prod.discount_price,
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <ArrowRight
                                                size={14}
                                                className="search-item-arrow"
                                            />
                                        </Link>
                                    ))}
                                </div>
                            )}

                            <div className="search-view-all-row">
                                <Link
                                    href={`${ROUTES.SHOP}?search=${encodeURIComponent(searchQuery)}`}
                                    className="search-view-all-link"
                                    onClick={() => setSearchFocused(false)}
                                >
                                    View all matching results for "{searchQuery}
                                    " →
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="search-empty-row">
                            <span>
                                No hardware found matching "
                                <strong>{searchQuery}</strong>". Try checking
                                keywords.
                            </span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default SearchBar;
