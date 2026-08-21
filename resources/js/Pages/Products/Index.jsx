import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import { productService } from '../../services';
import {
    ProductCard,
    Pagination,
    Spinner,
    Button,
    ProductCardSkeleton,
    EmptyState,
} from '../../Components';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { Filter, SlidersHorizontal, ArrowUpDown } from 'lucide-react';
import './Index.css';

export default function ProductListing({ categorySlug }) {
    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [totalCount, setTotalCount] = useState(0);
    const [sort, setSort] = useState('latest');
    const [wishlistIds, setWishlistIds] = useState([]);
    const [loadError, setLoadError] = useState(null);

    const fetchProducts = async () => {
        setLoading(true);
        try {
            const data = await productService.getProducts({
                page: page,
                sort: sort,
                category_slug: categorySlug,
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
    }, [page, sort, categorySlug]);

    const toggleWishlist = (productId) => {
        setWishlistIds((prev) =>
            prev.includes(productId)
                ? prev.filter((id) => id !== productId)
                : [...prev, productId],
        );
    };

    const pageTitle = categorySlug
        ? `${categorySlug.replace(/-/g, ' ').toUpperCase()} — ${siteConfig.name}`
        : `All Technology Products — ${siteConfig.name}`;

    return (
        <MainLayout>
            <Head title={pageTitle} />

            <div className="container plp-page-wrapper">
                <div className="plp-container">
                    {/* Header Banner */}
                    <div className="plp-header-banner">
                        <div>
                            <div className="breadcrumbs plp-breadcrumbs-spacer">
                                <Link href={ROUTES.HOME}>Home</Link> &gt;
                                <span className="current">
                                    {' '}
                                    {categorySlug
                                        ? categorySlug.replace(/-/g, ' ')
                                        : 'All Products'}
                                </span>
                            </div>
                            <h1 className="plp-title">
                                {categorySlug
                                    ? categorySlug.replace(/-/g, ' ')
                                    : 'Hardware Catalog'}
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
                                className="form-control plp-sort-select"
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
        </MainLayout>
    );
}
