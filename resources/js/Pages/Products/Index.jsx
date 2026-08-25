import React, {
    useState,
    useEffect,
    useCallback,
    useMemo,
    useRef,
} from 'react';
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
import { parseShopQuery, buildShopSearch } from '../../utils/shopQuery';
import { Filter, SlidersHorizontal, ArrowUpDown } from 'lucide-react';
import './Index.css';

export default function ProductListing({ categorySlug }) {
    /*
     * Paging, sorting and filtering used to start from hardcoded defaults, so
     * reloading page 3 of a filtered catalogue dropped the shopper back on
     * page 1 of everything — and a link to what they were looking at could not
     * be sent. The URL is the source of truth for all three now.
     */
    const initial = useMemo(
        () =>
            parseShopQuery(
                typeof window === 'undefined' ? '' : window.location.search,
            ),
        [],
    );

    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(initial.page);
    const [totalPages, setTotalPages] = useState(1);
    const [totalCount, setTotalCount] = useState(0);
    const [sort, setSort] = useState(initial.sort);
    const { wishlistIds, toggleWishlist } = useWishlist();
    const addToCart = useAddToCart();
    const [loadError, setLoadError] = useState(null);
    const [facets, setFacets] = useState(null);
    // Everything the shopper has narrowed by, kept in one object so a change
    // to any of it can reset paging in a single place.
    const [filters, setFilters] = useState(initial.filters);

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

    /*
     * replaceState rather than a push: this rewrites the address so the view
     * can be reloaded and shared, without stacking a history entry per click
     * that Back would have to walk through. Inertia keeps its own page object
     * in history.state, so that is passed straight back through.
     */
    useEffect(() => {
        if (typeof window === 'undefined') return;

        const search = buildShopSearch({ page, sort, filters: activeFilters });
        const next = window.location.pathname + search;

        if (next !== window.location.pathname + window.location.search) {
            window.history.replaceState(window.history.state, '', next);
        }
    }, [page, sort, filterKey, activeFilters]);

    /*
     * A new page of results starts at the top of the grid. Without this the
     * viewport stayed where the pagination bar had been, which on a shorter
     * last page left the shopper looking at the footer.
     */
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        document
            .getElementById('shop-results')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [page]);

    /**
     * Narrowing always returns to page one: staying on page 4 of a result set
     * that now has two pages shows an empty grid.
     */
    const applyFilters = useCallback((patch) => {
        setFilters((prev) => ({ ...prev, ...patch }));
        setPage(1);
    }, []);

    /*
     * A different category is a different selection; carrying price and brand
     * across would silently hide most of it.
     *
     * Only on an actual change, though. This also ran on mount, which wiped
     * the paging and filters the URL had just been read for — landing on
     * /products?page=2&in_stock=1 showed page one of everything.
     */
    const previousCategory = useRef(categorySlug);

    useEffect(() => {
        if (previousCategory.current === categorySlug) return;

        previousCategory.current = categorySlug;
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

                        <div className="plp-results" id="shop-results">
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
