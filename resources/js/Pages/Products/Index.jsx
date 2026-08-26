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

/**
 * The shop listing.
 *
 * `/offers` renders this too, with onSaleOnly, rather than a second listing
 * that would have to grow its own paging, filters, URL sync and skeletons and
 * then drift from this one.
 */
/*
 * The last facets we were given, kept outside the component.
 *
 * Choosing a category is an Inertia visit, which remounts this page, so
 * component state cannot carry the sidebar across it. Without somewhere to
 * hold them the filter had nothing to draw and fell back to placeholders on
 * every click — a skeleton for something the shopper was already looking at.
 * They are only a frame stale: the request for the new ones is already out.
 */
let lastFacets = null;

export default function ProductListing({ categorySlug, onSaleOnly = false }) {
    /*
     * Paging, sorting and filtering used to start from hardcoded defaults, so
     * reloading page 3 of a filtered catalogue dropped the shopper back on
     * page 1 of everything — and a link to what they were looking at could not
     * be sent. The URL is the source of truth for all three now.
     */
    /*
     * A deals page leads with the deepest discount; the catalogue leads with
     * what arrived most recently. Either can still be overridden from the
     * dropdown or the URL.
     */
    const defaultSort = onSaleOnly ? 'discount_high' : 'latest';

    const initial = useMemo(
        () =>
            parseShopQuery(
                typeof window === 'undefined' ? '' : window.location.search,
                defaultSort,
            ),
        [defaultSort],
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
    const [facets, setFacets] = useState(lastFacets);

    // In flight. The sidebar stays on screen but stops accepting clicks, so a
    // second choice cannot be made against counts that are about to change.
    const [facetsBusy, setFacetsBusy] = useState(true);
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

    // The offers page is a listing that is already narrowed; the shopper can
    // filter within it but cannot widen back out to full price.
    const requestFilters = useMemo(
        () =>
            onSaleOnly ? { ...activeFilters, on_sale: true } : activeFilters,
        [activeFilters, onSaleOnly],
    );

    const filterKey = JSON.stringify(requestFilters);

    const fetchProducts = async () => {
        setLoading(true);
        try {
            const data = await productService.getProducts({
                page: page,
                sort: sort,
                category_slug: categorySlug,
                ...requestFilters,
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

        setFacetsBusy(true);

        productService
            .getFilters({ category_slug: categorySlug, ...requestFilters })
            .then((data) => {
                if (cancelled) return;

                lastFacets = data;
                setFacets(data);
            })
            .catch(() => {
                // Leave whatever is on screen rather than emptying the sidebar
                // — a failed refresh should not take the filters away.
                if (!cancelled && facets === null) setFacets(null);
            })
            .finally(() => {
                if (!cancelled) setFacetsBusy(false);
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

        const search = buildShopSearch({
            page,
            sort,
            filters: activeFilters,
            defaultSort,
        });
        const next = window.location.pathname + search;

        if (next !== window.location.pathname + window.location.search) {
            window.history.replaceState(window.history.state, '', next);
        }
    }, [page, sort, filterKey, activeFilters, defaultSort]);

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

    const listingName = onSaleOnly
        ? 'Offers & Deals'
        : (readableCategory ?? 'All Products');

    const pageTitle = onSaleOnly
        ? `Offers & Deals — ${siteConfig.name}`
        : categorySlug
          ? `${categorySlug.replace(/-/g, ' ').toUpperCase()} — ${siteConfig.name}`
          : `All Technology Products — ${siteConfig.name}`;

    return (
        <MainLayout>
            <SEOHead
                title={
                    onSaleOnly
                        ? 'Offers & Deals — Discounted PC Hardware'
                        : readableCategory
                          ? `${readableCategory} — Price in Bangladesh`
                          : 'Shop All Computer Hardware & Components'
                }
                description={
                    onSaleOnly
                        ? 'Every discounted component, laptop and build currently in stock — genuine parts with official warranty and nationwide delivery.'
                        : readableCategory
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
                                <span className="current">{listingName}</span>
                            </div>
                            <h1 className="plp-title">
                                {onSaleOnly
                                    ? 'Offers & Deals'
                                    : readableCategory || 'Hardware Catalog'}
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
                                {/*
                                 * The API has always accepted these two and
                                 * the dropdown never offered them, so neither
                                 * was reachable without hand-editing the URL.
                                 */}
                                <option value="discount_high">
                                    Biggest Discount
                                </option>
                                <option value="price_low_high">
                                    Price: Low to High
                                </option>
                                <option value="price_high_low">
                                    Price: High to Low
                                </option>
                                <option value="name_asc">Name: A to Z</option>
                            </select>
                        </div>
                    </div>

                    <div className="plp-layout">
                        <ProductFilters
                            facets={facets}
                            value={activeFilters}
                            onChange={applyFilters}
                            categorySlug={categorySlug}
                            hideOnSale={onSaleOnly}
                            /* Placeholders only when there is nothing at all
                               to show — the very first listing of the session. */
                            loading={facets === null}
                            busy={facetsBusy}
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
                                            title={
                                                onSaleOnly
                                                    ? 'Nothing is on offer right now'
                                                    : categorySlug
                                                      ? `No products in ${readableCategory} yet`
                                                      : 'No products match those filters'
                                            }
                                            description={
                                                onSaleOnly
                                                    ? 'Check back soon — discounts change regularly.'
                                                    : categorySlug
                                                      ? 'This part of the catalogue is still being stocked. Everything else is a click away.'
                                                      : 'Try clearing a filter or two to widen the search.'
                                            }
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
