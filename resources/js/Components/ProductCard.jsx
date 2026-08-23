import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    ShoppingCart,
    Heart,
    RefreshCw,
    Star,
    CheckCircle,
    Flame,
    Eye,
} from 'lucide-react';
import { formatBdt, calculateDiscount } from '../utils/formatters';
import QuickViewModal from './QuickViewModal';
import ProductImage, { getProductImageUrl } from './ProductImage';
import { compareService } from '../services';
import useAppStore from '../store/useAppStore';
import { toast } from './Toast';
import { ROUTES } from '../constants/endpoints';

/**
 * Reusable Product Card Component (DRY & SSOT).
 * Supports variants: 'standard' | 'flash' | 'compact'
 */
export const ProductCard = ({
    product,
    variant = 'standard',
    onAddToCart,
    onToggleWishlist,
    isWishlisted = false,
}) => {
    // Hooks must run before any early return: React matches them up by call
    // order, so bailing out first means a card that renders once without a
    // product and then with one changes its hook count and throws
    // "Rendered more hooks than during the previous render".
    const [showQuickView, setShowQuickView] = useState(false);

    if (!product) return null;

    // Normalize prices & discounts
    const regularPrice = product.raw_old_price || product.price || 0;
    const discountPrice = product.raw_price || product.discount_price || null;
    const currentPrice =
        discountPrice && discountPrice < regularPrice
            ? discountPrice
            : regularPrice;
    const discountInfo = calculateDiscount(regularPrice, discountPrice);
    const imageSrc = getProductImageUrl(product);

    // `|| true` used to make this unconditionally true, so sold-out products
    // still rendered an enabled "Buy Now" button.
    const inStock =
        product.inStock !== undefined
            ? Boolean(product.inStock)
            : Number(product.stock_quantity ?? 0) > 0;

    // Social proof, straight from the API — no invented fallbacks.
    const rating = Number(product.rating ?? 0);
    const reviewCount = Number(product.reviews ?? 0);
    const soldCount = Number(product.sold ?? 0);
    const stockQuantity = Number(
        product.stockQuantity ?? product.stock_quantity ?? 0,
    );
    const totalStock = Number(product.totalStock ?? stockQuantity + soldCount);
    const soldPercent =
        totalStock > 0
            ? Math.min(100, Math.round((soldCount / totalStock) * 100))
            : 0;

    const handleAddToCart = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (onAddToCart) {
            onAddToCart(product);
        }
    };

    const handleToggleWishlist = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (onToggleWishlist) {
            onToggleWishlist(product);
        }
    };

    const handleCompare = async (e) => {
        e.preventDefault();
        e.stopPropagation();
        try {
            await compareService.addToComparison(product);
            const comp = await compareService.getComparison();
            useAppStore.getState().setCompareCount(comp.length || 1);
            toast.success(
                `Added "${product.name}" to comparison matrix!`,
                'Comparison Matrix',
            );
        } catch (error) {
            toast.warning(
                error.message ||
                    'You can compare a maximum of 4 items at a time.',
                'Compare Limit Reached',
            );
        }
    };

    const handleOpenQuickView = (e) => {
        e.preventDefault();
        e.stopPropagation();
        setShowQuickView(true);
    };

    if (variant === 'flash') {
        return (
            <>
                <div className="flash-product-card">
                    {/* Discount Badge */}
                    {discountInfo && (
                        <span className="card-badge discount-badge">
                            <Flame size={12} /> {discountInfo.percent}
                        </span>
                    )}

                    {/* Wishlist & Compare Quick Floating Actions */}
                    <div className="card-floating-actions">
                        <button
                            type="button"
                            onClick={handleOpenQuickView}
                            className="card-action-btn"
                            title="Quick View"
                        >
                            <Eye size={14} />
                        </button>
                        <button
                            type="button"
                            onClick={handleToggleWishlist}
                            className={`card-action-btn ${isWishlisted ? 'active' : ''}`}
                            title="Add to Wishlist"
                        >
                            <Heart
                                size={15}
                                fill={isWishlisted ? 'currentColor' : 'none'}
                            />
                        </button>
                        <button
                            type="button"
                            onClick={handleCompare}
                            className="card-action-btn"
                            title="Compare Product"
                        >
                            <RefreshCw size={14} />
                        </button>
                    </div>

                    {/* Product Image Thumbnail */}
                    <Link
                        href={ROUTES.PRODUCT_DETAIL(product.slug)}
                        className="product-image-box"
                    >
                        <ProductImage
                            product={product}
                            src={imageSrc}
                            alt={product.name}
                            loading="lazy"
                        />
                    </Link>

                    {/* Body Content */}
                    <div className="product-body">
                        <div className="product-brand-tag">
                            {product.brand?.name || product.brand || 'Hardware'}
                        </div>
                        <h4 className="product-title">
                            <Link href={ROUTES.PRODUCT_DETAIL(product.slug)}>
                                {product.name}
                            </Link>
                        </h4>

                        {/* Key Specifications */}
                        {product.specs && product.specs.length > 0 && (
                            <ul className="product-specs-list">
                                {product.specs.slice(0, 3).map((spec, idx) => (
                                    <li key={idx}>• {spec}</li>
                                ))}
                            </ul>
                        )}

                        {/* Ratings — real values only; a product with no reviews
                            says so rather than borrowing an invented score. */}
                        <div className="product-rating-row">
                            {reviewCount > 0 ? (
                                <>
                                    <div className="star-rating">
                                        <Star
                                            size={13}
                                            fill="#F59E0B"
                                            color="#F59E0B"
                                        />
                                        <span>{rating.toFixed(1)}</span>
                                    </div>
                                    <span className="review-count">
                                        ({reviewCount}{' '}
                                        {reviewCount === 1
                                            ? 'review'
                                            : 'reviews'}
                                        )
                                    </span>
                                </>
                            ) : (
                                <span className="review-count">
                                    No reviews yet
                                </span>
                            )}
                        </div>

                        {/* Stock bar — driven by real stock and real units sold. */}
                        <div className="stock-progress-container">
                            <div className="stock-labels">
                                <span>
                                    {stockQuantity > 0
                                        ? `Available: ${stockQuantity} units`
                                        : 'Out of stock'}
                                </span>
                                <span className="sold-count">
                                    {soldCount} Sold
                                </span>
                            </div>
                            <div className="progress-track">
                                <div
                                    className="progress-bar-fill"
                                    style={{
                                        width: `${soldPercent}%`,
                                    }}
                                ></div>
                            </div>
                        </div>

                        {/* Price Stack & Buy Action */}
                        <div className="flash-card-footer">
                            <div className="price-stack">
                                <span className="current-price">
                                    {formatBdt(currentPrice)}
                                </span>
                                {discountInfo && (
                                    <span className="old-price">
                                        {formatBdt(regularPrice)}
                                    </span>
                                )}
                            </div>

                            <button
                                type="button"
                                onClick={handleAddToCart}
                                className="btn-add-cart"
                                title="Add to Cart"
                            >
                                <ShoppingCart size={16} /> Buy Now
                            </button>
                        </div>
                    </div>
                </div>

                <QuickViewModal
                    show={showQuickView}
                    onClose={() => setShowQuickView(false)}
                    product={product}
                />
            </>
        );
    }

    // Standard Grid Product Card
    return (
        <>
            <div className="standard-product-card">
                {discountInfo && (
                    <span className="card-badge discount-badge">
                        {discountInfo.percent} OFF
                    </span>
                )}

                {/* Quick Floating Action Tools */}
                <div className="card-floating-actions">
                    <button
                        type="button"
                        onClick={handleOpenQuickView}
                        className="card-action-btn"
                        title="Quick View"
                    >
                        <Eye size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={handleToggleWishlist}
                        className={`card-action-btn ${isWishlisted ? 'active' : ''}`}
                        title="Add to Wishlist"
                    >
                        <Heart
                            size={15}
                            fill={isWishlisted ? 'currentColor' : 'none'}
                        />
                    </button>
                    <button
                        type="button"
                        onClick={handleCompare}
                        className="card-action-btn"
                        title="Compare Product"
                    >
                        <RefreshCw size={14} />
                    </button>
                </div>

                {/* Image Thumbnail */}
                <Link
                    href={ROUTES.PRODUCT_DETAIL(product.slug)}
                    className="product-image-box"
                >
                    <ProductImage
                        product={product}
                        src={imageSrc}
                        alt={product.name}
                        loading="lazy"
                    />
                </Link>

                {/* Content Details */}
                <div className="product-body">
                    <div className="product-brand-tag">
                        {product.brand?.name ||
                            product.brand ||
                            'Authorized Brand'}
                    </div>
                    <h4 className="product-title">
                        <Link href={ROUTES.PRODUCT_DETAIL(product.slug)}>
                            {product.name}
                        </Link>
                    </h4>

                    {/* Specs */}
                    {product.specs && product.specs.length > 0 && (
                        <ul className="product-specs-list">
                            {product.specs.slice(0, 3).map((spec, idx) => (
                                <li key={idx}>• {spec}</li>
                            ))}
                        </ul>
                    )}

                    {/* Stock Status Pill */}
                    <div className="stock-status-row">
                        {inStock ? (
                            <span className="in-stock-pill">
                                <CheckCircle size={12} /> In Stock
                            </span>
                        ) : (
                            <span className="out-of-stock-pill">Pre Order</span>
                        )}
                    </div>

                    {/* Price & Add to Cart Footer */}
                    <div className="product-card-footer">
                        <div className="price-stack">
                            <span className="current-price">
                                {formatBdt(currentPrice)}
                            </span>
                            {discountInfo && (
                                <span className="old-price">
                                    {formatBdt(regularPrice)}
                                </span>
                            )}
                        </div>

                        <button
                            type="button"
                            onClick={handleAddToCart}
                            className="btn-add-cart"
                            title="Add to Cart"
                        >
                            <ShoppingCart size={16} /> Buy
                        </button>
                    </div>
                </div>
            </div>

            <QuickViewModal
                show={showQuickView}
                onClose={() => setShowQuickView(false)}
                product={product}
            />
        </>
    );
};

export default ProductCard;
