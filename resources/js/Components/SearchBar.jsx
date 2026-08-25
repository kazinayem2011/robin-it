import React, { useState, useEffect, useRef } from 'react';
import { Link, router } from '@inertiajs/react';
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
import { ROUTES } from '../constants/endpoints';
import { formatBdt } from '../utils/formatters';
import ProductImage from './ProductImage';

/**
 * Enhanced High-Conversion SearchBar component with multi-facet instant suggestions (SSOT)
 */
export const SearchBar = ({ onSearch }) => {
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('all');
    const [suggestions, setSuggestions] = useState({
        products: [],
        categories: [],
        brands: [],
    });
    const [isSearching, setIsSearching] = useState(false);
    const [searchFocused, setSearchFocused] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(-1);
    const tagsRef = useRef(null);

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

    const handleSubmit = (e) => {
        e?.preventDefault();
        setSearchFocused(false);
        if (onSearch) {
            onSearch(searchQuery, selectedCategory);
        } else if (searchQuery.trim()) {
            const url = `${ROUTES.SHOP}?search=${encodeURIComponent(searchQuery.trim())}${
                selectedCategory !== 'all'
                    ? `&category_slug=${selectedCategory}`
                    : ''
            }`;
            router.visit(url);
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
            >
                <div className="search-category-selector">
                    <SlidersHorizontal size={14} className="cat-select-icon" />
                    <select
                        value={selectedCategory}
                        onChange={(e) => setSelectedCategory(e.target.value)}
                    >
                        {siteConfig.searchCategories.map((cat) => (
                            <option key={cat.value} value={cat.value}>
                                {cat.label}
                            </option>
                        ))}
                    </select>
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
                                                    href={`${ROUTES.SHOP}?brand=${br.slug}`}
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
                                                    <span className="search-item-brand">
                                                        {prod.brand?.name ||
                                                            siteConfig.name}
                                                    </span>
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
