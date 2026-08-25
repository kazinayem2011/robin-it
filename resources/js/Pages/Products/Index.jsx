import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import { productService, cartService } from '../../services';
import {
    ProductCard,
    ProductFilters,
    SEOHead,
    Pagination,
    Button,
    ProductCardSkeleton,
    EmptyState,
    toast,
} from '../../Components';
import useAppStore from '../../store/useAppStore';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { useWishlist, useAddToCart } from '../../hooks';
import { Filter, SlidersHorizontal, ArrowUpDown } from 'lucide-react';
import './Index.css';

export default function ProductListing({ categorySlug }) {
    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [totalCount, setTotalCount] = useState(0);
    const [sort, setSort] = useState('latest');
    const { wishlistIds, toggleWishlist } = useWishlist();
    const addToCart = useAddToCart();
    const [loadError, setLoadError] = useState(null);
    const [facets, setFacets] = useState(null);
    // Everything the shopper has narrowed by, kept in one object so a change
    // to any of it can reset paging in a single place.
    const [filters, setFilters] = useState({});

    // Only the keys that are actually set, so the URL and the request stay
    // free of `undefined` noise.
    const activeFilters = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(filters).filter(
                    ([, v]) =>
                        v !== undefined &&
                        v !== '' &&
                        !(Array.isArray(v) && v.length === 0),
                ),
            ),
        [filters],
    );

    const filterKey = JSON.stringify(activeFilters);

    const fetchProducts = async () => {
        setLoading(true);
        try {
            const data = await productService.getProducts({
                page: page,
                sort: sort,
                category_slug: categorySlug,
                ...activeFilters,
            });

            setProducts(data.items);
            setTotalPages(data.meta.last_page || 1);
            setTotalCount(data.meta.total ?? data.items.length);
            setLoadError(null);
        } catch (error) {
            console.error('Failed to fetch products', error);
            setProducts([]);
            setLoadError(
                error?.message ||
                    'We could not load products right now. Please try again.',
            );
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchProducts();
        // fetchProducts closes over the current filters; depending on the
        // function itself would re-fire on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, sort, categorySlug, filterKey]);

    // The facets describe the selection, so they follow everything except
    // paging and sorting.
    useEffect(() => {
        let cancelled = false;

        productService
            .getFilters({ category_slug: categorySlug, ...activeFilters })
            .then((data) => {
                if (!cancelled) setFacets(data);
            })
            .catch(() => {
                if (!cancelled) setFacets(null);
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [categorySlug, filterKey]);

    /**
     * Narrowing always returns to page one: staying on page 4 of a result set
     * that now has two pages shows an empty grid.
     */
    const applyFilters = useCallback((patch) => {
        setFilters((prev) => ({ ...prev, ...patch }));
        setPage(1);
    }, []);

    // A different category is a different selection; carrying price and brand
    // across would silently hide most of it.
    useEffect(() => {
        setFilters({});
        setPage(1);
    }, [categorySlug]);

    /**
     * ProductCard's Buy button calls this. It was never passed, so every Buy
     * button in the shop did nothing at all — no request, no message, no
     * navigation.
     */

    // Prefer the catalogue's own name for the category. Prettifying the slug
    // turned "pc-case" into "Pc Case", which is not what the shop calls it.
    const readableCategory = categorySlug
        ? (facets?.category?.name ??
          categorySlug
              .replace(/-/g, ' ')
              .replace(/\b\w/g, (c) => c.toUpperCase()))
        : null;

    const pageTitle = categorySlug
        ? `${categorySlug.replace(/-/g, ' ').toUpperCase()} — ${siteConfig.name}`
        : `All Technology Products — ${siteConfig.name}`;

    return (
        <MainLayout>
            <SEOHead
                title={
                    readableCategory
                        ? `${readableCategory} — Price in Bangladesh`
                        : 'Shop All Computer Hardware & Components'
                }
                description={
                    readableCategory
                        ? `Buy genuine ${readableCategory} at the best price in Bangladesh, with official warranty and nationwide delivery.`
                        : 'Browse processors, graphics cards, motherboards, memory and complete builds — genuine parts with official warranty and nationwide delivery.'
                }
            />

            <Head title={pageTitle} />

            <div className="container plp-page-wrapper">
                <div className="plp-container">
                    {/* Header Banner */}
                    <div className="plp-header-banner">
                        <div>
                            <div className="breadcrumbs plp-breadcrumbs-spacer">
                                <Link href={ROUTES.HOME}>Home</Link>
                                <span className="current">
                                    {readableCategory || 'All Products'}
                                </span>
                            </div>
                            <h1 className="plp-title">
                                {readableCategory || 'Hardware Catalog'}
                            </h1>
                            <span className="plp-item-count">
                                Showing {products.length} of {totalCount} items
                            </span>
                        </div>

                        <div className="plp-controls">
                            <span className="plp-sort-label">
                                <ArrowUpDown size={14} /> Sort:
                            </span>
                            <select
                                value={sort}
                                onChange={(e) => setSort(e.target.value)}
                                className="plp-sort-select"
                            >
                                <option value="latest">Latest Arrivals</option>
                                <option value="price_low_high">
                                    Price: Low to High
                                </option>
                                <option value="price_high_low">
                                    Price: High to Low
                                </option>
                            </select>
                        </div>
                    </div>

                    <div className="plp-layout">
                        <ProductFilters
                            facets={facets}
                            value={activeFilters}
                            onChange={applyFilters}
                            categorySlug={categorySlug}
                        />

                        <div className="plp-results">
                            {/* Product Grid Area with Skeleton Loading Shimmers */}
                            <div className="standard-products-grid plp-grid-spacer">
                                {loading ? (
                                    Array.from({ length: 8 }).map((_, idx) => (
                                        <ProductCardSkeleton key={idx} />
                                    ))
                                ) : loadError ? (
                                    <div className="plp-full-span">
                                        <EmptyState
                                            title="We couldn't load these products"
                                            description={loadError}
                                            actionLabel="Try Again"
                                            onAction={fetchProducts}
                                        />
                                    </div>
                                ) : products.length === 0 ? (
                                    <div className="plp-full-span">
                                        <EmptyState
                                            title="No products found in this category"
                                            description="Try selecting a different category or clearing your search/filter criteria."
                                            actionLabel="Browse All Hardware"
                                            actionHref={ROUTES.SHOP}
                                        />
                                    </div>
                                ) : (
                                    products.map((product) => (
                                        <ProductCard
                                            key={product.id}
                                            product={product}
                                            variant="standard"
                                            isWishlisted={wishlistIds.includes(
                                                product.id,
                                            )}
                                            onAddToCart={addToCart}
                                            onToggleWishlist={() =>
                                                toggleWishlist(product.id)
                                            }
                                        />
                                    ))
                                )}
                            </div>

                            {/* Reusable Pagination */}
                            <Pagination
                                currentPage={page}
                                totalPages={totalPages}
                                onPageChange={setPage}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </MainLayout>
    );
}
