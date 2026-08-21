import React, { useState, useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import {
    productService,
    cartService,
    compareService,
    wishlistService,
    reviewService,
} from '../../services';
import {
    Button,
    Spinner,
    SEOHead,
    toast,
    CountdownTimer,
    Tabs,
    RatingBreakdown,
    ReviewForm,
    ReviewList,
} from '../../Components';
import useAppStore from '../../store/useAppStore';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import {
    Heart,
    Scale,
    ShoppingCart,
    Check,
    ShieldCheck,
    Truck,
    RefreshCw,
    Star,
} from 'lucide-react';
import './Show.css';

export default function ProductDetails(props) {
    const productSlug =
        props.productSlug ||
        props.slug ||
        (typeof window !== 'undefined'
            ? window.location.pathname.split('/').filter(Boolean).pop()
            : '');

    const [product, setProduct] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [activeTab, setActiveTab] = useState('specifications');
    const [selectedImageIndex, setSelectedImageIndex] = useState(0);
    const [quantity, setQuantity] = useState(1);
    const [addedToCart, setAddedToCart] = useState(false);
    const [addingToCart, setAddingToCart] = useState(false);

    // Reviews & Ratings State
    const [reviewsData, setReviewsData] = useState({
        average_rating: 4.9,
        total_reviews: 0,
        breakdown: {},
        reviews: [],
    });
    const [submittingReview, setSubmittingReview] = useState(false);

    useEffect(() => {
        if (!productSlug) return;

        const fetchProductAndReviews = async () => {
            setLoading(true);
            setError(null);
            try {
                const [prodRes, revsRes] = await Promise.allSettled([
                    productService.getProductBySlug(productSlug),
                    reviewService.getProductReviews(productSlug),
                ]);

                if (prodRes.status === 'fulfilled') {
                    const prodData = prodRes.value.data || prodRes.value;
                    setProduct(prodData);
                } else {
                    setError('Product not found or unavailable.');
                }

                if (revsRes.status === 'fulfilled') {
                    setReviewsData(revsRes.value);
                }
            } catch (err) {
                setError('Failed to load product information.');
            } finally {
                setLoading(false);
            }
        };

        fetchProductAndReviews();
    }, [productSlug]);

    const handleReviewSubmit = async (reviewFormData) => {
        setSubmittingReview(true);
        try {
            const res = await reviewService.submitReview(
                productSlug,
                reviewFormData,
            );
            toast.success(res.message || 'Review submitted successfully!');
            // Refresh reviews
            const revs = await reviewService.getProductReviews(productSlug);
            setReviewsData(revs);
        } catch (err) {
            toast.error(err?.message || 'Failed to submit review.');
        } finally {
            setSubmittingReview(false);
        }
    };

    const handleAddToCart = async () => {
        setAddingToCart(true);
        try {
            await cartService.addToCart(product.id, quantity);
            useAppStore.getState().fetchCartCount();
            setAddedToCart(true);
            toast.success(
                `Added ${quantity}x "${product.name}" to cart!`,
                'Cart Updated',
            );
            setTimeout(() => setAddedToCart(false), 2500);
        } catch (err) {
            console.error('Failed to add to cart', err);
            // e.g. "Only 2 left in stock for ..." — far more useful than "Failed".
            toast.error(
                err?.message || 'Failed to add product to cart.',
                'Could Not Add To Cart',
            );
        } finally {
            setAddingToCart(false);
        }
    };

    const handleBuyNow = async () => {
        setAddingToCart(true);
        try {
            await cartService.addToCart(product.id, quantity);
            useAppStore.getState().fetchCartCount();
            router.visit(ROUTES.CHECKOUT);
        } catch (err) {
            console.error('Failed to process Buy Now', err);
            toast.error(
                err?.message || 'We could not start checkout for this item.',
                'Could Not Add To Cart',
            );
            setAddingToCart(false);
        }
    };

    const handleToggleWishlist = async () => {
        try {
            const { wishlisted, items } = await wishlistService.toggleWishlist(
                product.id,
            );
            useAppStore.getState().setWishlistCount(items.length);
            toast.success(
                wishlisted
                    ? `Saved "${product.name}" to your wishlist.`
                    : `Removed "${product.name}" from your wishlist.`,
                'Wishlist Updated',
            );
        } catch (err) {
            console.error('Failed to toggle wishlist', err);
            toast.error(
                err?.message ||
                    'Please sign in to save products to your wishlist.',
                'Wishlist',
            );
        }
    };

    const handleAddToCompare = async () => {
        try {
            await compareService.addToComparison(product);
            const comp = await compareService.getComparison();
            useAppStore.getState().setCompareCount(comp.length || 1);
            toast.success(
                `Added "${product.name}" to comparison matrix!`,
                'Compare Matrix',
            );
        } catch (err) {
            toast.warning(
                err.message ||
                    'You can compare a maximum of 4 items at a time.',
                'Compare Limit Reached',
            );
        }
    };

    if (loading) {
        return (
            <MainLayout>
                <div className="pdp-loading-container">
                    <Spinner size="lg" text="Loading product details..." />
                </div>
            </MainLayout>
        );
    }

    if (error || !product) {
        return (
            <MainLayout>
                <div className="pdp-error-container">
                    <h2>{error || 'Something went wrong'}</h2>
                    <Link href={ROUTES.SHOP} className="btn btn-primary mt-3">
                        Back to Store
                    </Link>
                </div>
            </MainLayout>
        );
    }

    const images =
        product.images && product.images.length > 0
            ? product.images.map((img) => img.image_path)
            : [
                  siteConfig.productPlaceholder ||
                      '/images/product-placeholder.svg',
              ];

    const schemaData = {
        '@context': 'https://schema.org/',
        '@type': 'Product',
        name: product.name,
        image: images[0],
        description: product.short_description || product.name,
        brand: {
            '@type': 'Brand',
            name: product.brand?.name || 'Genuine Brand',
        },
        offers: {
            '@type': 'Offer',
            priceCurrency: 'BDT',
            price: product.effective_price ?? product.price,
            availability: 'https://schema.org/InStock',
            seller: {
                '@type': 'Organization',
                name: siteConfig.name,
            },
        },
    };

    return (
        <MainLayout>
            <SEOHead
                title={product.name}
                description={product.short_description || product.name}
                image={images[0]}
                type="product"
                schemaData={schemaData}
            />

            <div className="container pdp-page-wrapper">
                <div className="pdp-container">
                    {/* Breadcrumbs */}
                    <div className="breadcrumbs">
                        <Link href={ROUTES.HOME}>Home</Link> &gt;
                        <Link
                            href={ROUTES.SHOP_CATEGORY(
                                product.category?.slug || '',
                            )}
                        >
                            {product.category?.name || 'Category'}
                        </Link>{' '}
                        &gt;
                        <span className="current">{product.name}</span>
                    </div>

                    {/* Top Section: Image & Basic Info */}
                    <div className="pdp-top">
                        {/* Image Gallery */}
                        <div className="pdp-gallery">
                            <div className="main-image">
                                <ProductImage
                                    src={
                                        images[selectedImageIndex] || images[0]
                                    }
                                    product={product}
                                    alt={product.name}
                                />
                            </div>
                            {images.length > 1 && (
                                <div className="thumbnail-list">
                                    {images.map((img, idx) => (
                                        <button
                                            key={idx}
                                            type="button"
                                            className={`thumbnail-stub ${selectedImageIndex === idx ? 'active' : ''}`}
                                            onClick={() =>
                                                setSelectedImageIndex(idx)
                                            }
                                        >
                                            <ProductImage
                                                src={img}
                                                alt={`Thumbnail ${idx + 1}`}
                                            />
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Product Summary */}
                        <div className="pdp-summary">
                            <h1 className="pdp-title">{product.name}</h1>

                            <div className="pdp-meta">
                                <div className="meta-item">
                                    <span className="meta-label">Brand:</span>
                                    <span className="meta-value">
                                        {product.brand?.name || 'N/A'}
                                    </span>
                                </div>
                                <div className="meta-item">
                                    <span className="meta-label">Status:</span>
                                    <span className="meta-value stock-status">
                                        {product.in_stock
                                            ? `In Stock (${product.stock_quantity} available)`
                                            : 'Out of Stock'}
                                    </span>
                                </div>
                                <div className="meta-item">
                                    <span className="meta-label">
                                        Product Code:
                                    </span>
                                    <span className="meta-value">
                                        RC-{product.id}
                                    </span>
                                </div>
                            </div>

                            <div className="pdp-pricing">
                                {product.has_discount ? (
                                    <>
                                        <div className="price-current">
                                            {formatBdt(product.effective_price)}
                                        </div>
                                        <div className="price-old">
                                            {formatBdt(product.price)}
                                        </div>
                                        <CountdownTimer
                                            label="LIMITED DEAL:"
                                            variant="pill"
                                            showIcon={true}
                                            iconType="flame"
                                        />
                                    </>
                                ) : (
                                    <div className="price-current">
                                        {formatBdt(product.price)}
                                    </div>
                                )}
                            </div>

                            <div className="pdp-short-desc">
                                <ul>
                                    {product.short_description
                                        ?.split('\n')
                                        .map((line, i) => (
                                            <li key={i}>{line}</li>
                                        )) || (
                                        <li>
                                            100% Genuine product with official
                                            brand warranty.
                                        </li>
                                    )}
                                </ul>
                            </div>

                            <div className="pdp-actions">
                                <div className="quantity-selector">
                                    <button
                                        type="button"
                                        disabled={quantity <= 1}
                                        onClick={() =>
                                            setQuantity((prev) =>
                                                Math.max(1, prev - 1),
                                            )
                                        }
                                    >
                                        -
                                    </button>
                                    <input
                                        type="number"
                                        value={quantity}
                                        readOnly
                                    />
                                    <button
                                        type="button"
                                        disabled={
                                            product?.stock_quantity !==
                                                undefined &&
                                            quantity >= product.stock_quantity
                                        }
                                        onClick={() =>
                                            setQuantity((prev) =>
                                                Math.min(
                                                    product?.stock_quantity ||
                                                        99,
                                                    prev + 1,
                                                ),
                                            )
                                        }
                                    >
                                        +
                                    </button>
                                </div>
                                <Button
                                    variant="primary"
                                    size="lg"
                                    disabled={product?.stock_quantity === 0}
                                    onClick={handleBuyNow}
                                >
                                    {product?.stock_quantity === 0
                                        ? 'Out of Stock'
                                        : 'Buy Now'}
                                </Button>
                                <Button
                                    variant={addedToCart ? 'dark' : 'secondary'}
                                    size="lg"
                                    disabled={product?.stock_quantity === 0}
                                    onClick={handleAddToCart}
                                    loading={addingToCart}
                                    icon={addedToCart ? Check : ShoppingCart}
                                >
                                    {product?.stock_quantity === 0
                                        ? 'Out of Stock'
                                        : addedToCart
                                          ? 'Added to Cart'
                                          : 'Add to Cart'}
                                </Button>
                            </div>

                            <div className="pdp-secondary-actions">
                                <button
                                    type="button"
                                    className="btn-text"
                                    onClick={handleToggleWishlist}
                                >
                                    <Heart size={15} /> Add to Wishlist
                                </button>
                                <button
                                    type="button"
                                    className="btn-text"
                                    onClick={handleAddToCompare}
                                >
                                    <Scale size={15} /> Add to Compare
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Bottom Section: Reusable Tabs */}
                    <div className="pdp-tabs-section">
                        <Tabs
                            tabs={[
                                {
                                    key: 'specifications',
                                    label: 'Specifications',
                                },
                                {
                                    key: 'description',
                                    label: 'Description',
                                },
                                {
                                    key: 'reviews',
                                    label: 'Reviews',
                                    badge: reviewsData.total_reviews || 0,
                                },
                            ]}
                            activeTab={activeTab}
                            onChange={setActiveTab}
                            variant="line"
                        />

                        <div className="tab-content">
                            {activeTab === 'specifications' && (
                                <div className="specifications-table">
                                    <h3>Technical Specifications</h3>
                                    {product.specifications &&
                                    product.specifications.length > 0 ? (
                                        <table>
                                            <tbody>
                                                {product.specifications.map(
                                                    (spec) => (
                                                        <tr key={spec.id}>
                                                            <td className="spec-name">
                                                                {spec.name}
                                                            </td>
                                                            <td className="spec-value">
                                                                {spec.value}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    ) : (
                                        <p>
                                            Standard official specifications
                                            apply. Contact support for
                                            datasheet.
                                        </p>
                                    )}
                                </div>
                            )}

                            {activeTab === 'description' && (
                                <div className="description-content">
                                    <h3>Product Description</h3>
                                    <div
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                product.description ||
                                                '<p>Genuine product supplied with official manufacturer warranty and full accessories.</p>',
                                        }}
                                    ></div>
                                </div>
                            )}

                            {activeTab === 'reviews' && (
                                <div className="reviews-tab-content">
                                    {/* Reusable Rating Score & Breakdown Component */}
                                    <RatingBreakdown
                                        averageRating={
                                            reviewsData.average_rating || 5
                                        }
                                        totalReviews={
                                            reviewsData.total_reviews || 0
                                        }
                                        breakdown={
                                            reviewsData.breakdown || {
                                                5: 0,
                                                4: 0,
                                                3: 0,
                                                2: 0,
                                                1: 0,
                                            }
                                        }
                                    />

                                    {/* Verified Buyer Permission Gate */}
                                    {reviewsData.can_review ? (
                                        <ReviewForm
                                            onSubmit={handleReviewSubmit}
                                            loading={submittingReview}
                                        />
                                    ) : reviewsData.already_reviewed ? (
                                        <div className="verified-buyer-notice success">
                                            <Check
                                                size={20}
                                                className="text-success"
                                            />
                                            <div>
                                                <strong>
                                                    Verified Review Published
                                                </strong>
                                                <p>
                                                    Thank you! Your verified
                                                    purchase review is live for
                                                    this product.
                                                </p>
                                            </div>
                                        </div>
                                    ) : !reviewsData.is_logged_in ? (
                                        <div className="verified-buyer-notice info">
                                            <ShieldCheck
                                                size={20}
                                                className="text-primary"
                                            />
                                            <div>
                                                <strong>
                                                    Verified Purchase Required
                                                </strong>
                                                <p>
                                                    Only customers who have
                                                    purchased this product from
                                                    Robin IT can write a review.{' '}
                                                    <Link
                                                        href={ROUTES.LOGIN}
                                                        style={{
                                                            color: 'var(--primary)',
                                                            fontWeight: 700,
                                                        }}
                                                    >
                                                        Log in to your account
                                                        &rarr;
                                                    </Link>
                                                </p>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="verified-buyer-notice warning">
                                            <ShieldCheck
                                                size={20}
                                                className="text-muted"
                                            />
                                            <div>
                                                <strong>
                                                    Verified Purchase Required
                                                </strong>
                                                <p>
                                                    Only verified buyers who
                                                    have purchased this product
                                                    from Robin IT can submit a
                                                    review.
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {/* Reusable Customer Reviews Feed List Component */}
                                    <ReviewList
                                        reviews={reviewsData.reviews || []}
                                        totalReviews={
                                            reviewsData.total_reviews || 0
                                        }
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </MainLayout>
    );
}
