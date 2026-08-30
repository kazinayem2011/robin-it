import React, { useState, useEffect, useMemo } from 'react';
import { Link, router } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import {
    productService,
    cartService,
    compareService,
    wishlistService,
    reviewService,
} from '../../services';
import BackInStockForm from '../../Components/BackInStockForm';
import BranchAvailability from '../../Components/BranchAvailability';
import Button from '../../Components/Button';
import CountdownTimer from '../../Components/CountdownTimer';
// The gallery renders <ProductImage> but never imported it, so the whole page
// threw "ProductImage is not defined" and rendered nothing at all.
import ProductImage from '../../Components/ProductImage';
import ProductQuestions from '../../Components/ProductQuestions';
import RatingBreakdown from '../../Components/RatingBreakdown';
import ReviewForm from '../../Components/ReviewForm';
import ReviewList from '../../Components/ReviewList';
import SEOHead from '../../Components/SEOHead';
import { ProductDetailSkeleton } from '../../Components/Skeleton';
import Tabs from '../../Components/Tabs';
import { toast } from '../../Components/Toast';
import useAppStore from '../../store/useAppStore';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import {
    Heart,
    Scale,
    ShoppingCart,
    Check,
    Clock,
    ShieldCheck,
} from 'lucide-react';
import './Show.css';

/**
 * Specs arranged into the sections the admin gave them, preserving entry order
 * both between groups and within one.
 *
 * Rows with no group collect under an empty key rather than being dropped or
 * given an invented heading: every spec written before grouping existed has a
 * null group, and those products still have to render.
 */
const groupSpecifications = (specifications) => {
    const order = [];
    const bucket = new Map();

    specifications.forEach((spec) => {
        const group = (spec.group || '').trim();

        if (!bucket.has(group)) {
            bucket.set(group, []);
            order.push(group);
        }

        bucket.get(group).push(spec);
    });

    return order.map((group) => ({ group, items: bucket.get(group) }));
};

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
    const [selectedVariantId, setSelectedVariantId] = useState(null);

    /*
     * On a variant product the price and the stock belong to the option, not to
     * the product. Everything below reads through these so the page can never
     * show the parent's numbers while the shopper is buying an option.
     */
    const variants = useMemo(
        () => (product?.has_variants ? product.active_variants || [] : []),
        [product],
    );

    const selectedVariant = useMemo(
        () => variants.find((v) => v.id === selectedVariantId) || null,
        [variants, selectedVariantId],
    );

    const availableStock = product?.has_variants
        ? (selectedVariant?.stock_quantity ?? 0)
        : (product?.stock_quantity ?? 0);

    // A variant product with nothing chosen yet cannot be bought.
    const needsVariantChoice =
        Boolean(product?.has_variants) && !selectedVariant;
    const outOfStock = !needsVariantChoice && availableStock <= 0;

    /*
     * Pre-order is set on the product, so it covers every option of a variant
     * product. An empty shelf then means "ships when the delivery lands"
     * rather than "you cannot have this".
     */
    const allowsPreorder = Boolean(product?.allow_preorder);
    const isPreorder = outOfStock && allowsPreorder;
    const soldOut = outOfStock && !allowsPreorder;

    const releaseDate = product?.preorder_release_at
        ? new Date(product.preorder_release_at).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : null;

    // Reviews & Ratings State
    const [reviewsData, setReviewsData] = useState({
        average_rating: 4.9,
        total_reviews: 0,
        breakdown: {},
        reviews: [],
    });
    const [submittingReview, setSubmittingReview] = useState(false);
    const [questions, setQuestions] = useState([]);

    const loadQuestions = React.useCallback(() => {
        if (!productSlug) return;
        fetch(`/api/products/${productSlug}/questions`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setQuestions(d?.data?.questions || []))
            .catch(() => setQuestions([]));
    }, [productSlug]);

    useEffect(() => {
        loadQuestions();
    }, [loadQuestions]);

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

                    // Land on something buyable rather than making the shopper
                    // discover which options are sold out by clicking each one.
                    if (prodData?.has_variants) {
                        const options = prodData.active_variants || [];
                        const firstInStock = options.find(
                            (v) => v.stock_quantity > 0,
                        );
                        setSelectedVariantId(
                            (firstInStock || options[0])?.id ?? null,
                        );
                    }
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
        if (needsVariantChoice) {
            toast.error('Please choose an option first.', 'Almost There');
            return;
        }

        setAddingToCart(true);
        try {
            await cartService.addToCart(
                product.id,
                quantity,
                selectedVariantId,
            );
            useAppStore.getState().fetchCartCount();
            setAddedToCart(true);
            toast.success(
                `Added ${quantity}x "${product.name}${
                    selectedVariant ? ` (${selectedVariant.name})` : ''
                }" to cart!`,
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
        if (needsVariantChoice) {
            toast.error('Please choose an option first.', 'Almost There');
            return;
        }

        setAddingToCart(true);
        try {
            await cartService.addToCart(
                product.id,
                quantity,
                selectedVariantId,
            );
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
            <>
                <div className="container">
                    <ProductDetailSkeleton />
                </div>
            </>
        );
    }

    if (error || !product) {
        return (
            <>
                <div className="pdp-error-container">
                    <h2>{error || 'Something went wrong'}</h2>
                    <Link href={ROUTES.SHOP} className="btn btn-primary mt-3">
                        Back to Store
                    </Link>
                </div>
            </>
        );
    }

    const productImages =
        product.images && product.images.length > 0
            ? product.images.map((img) => img.image_path)
            : [
                  siteConfig.productPlaceholder ||
                      '/images/product-placeholder.svg',
              ];

    // An option can carry its own shot — a white card looks nothing like the
    // black one. It leads the gallery so the picture matches what is selected,
    // with the product's own images still available behind it.
    const images = selectedVariant?.image_url
        ? [
              selectedVariant.image_url,
              ...productImages.filter((i) => i !== selectedVariant.image_url),
          ]
        : productImages;

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
        <>
            <SEOHead
                title={product.meta_title || product.name}
                description={
                    product.meta_description ||
                    product.short_description ||
                    product.name
                }
                keywords={product.meta_keyword || undefined}
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
                                        {/* The label is the server's, so a shop
                                            can say "2-3 Days" or "Call for
                                            Price" rather than only in/out. The
                                            count is still ours: it depends on
                                            the chosen option, which the server
                                            does not know. */}
                                        {needsVariantChoice
                                            ? 'Choose an option'
                                            : availableStock > 0
                                              ? `In Stock (${availableStock} available)`
                                              : product.stock_status_label ||
                                                (isPreorder
                                                    ? 'Pre-Order'
                                                    : 'Out of Stock')}
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
                                {(selectedVariant ?? product).has_discount ? (
                                    <>
                                        <div className="price-current">
                                            {formatBdt(
                                                (selectedVariant ?? product)
                                                    .effective_price,
                                            )}
                                        </div>
                                        <div className="price-old">
                                            {formatBdt(
                                                selectedVariant?.price ??
                                                    product.price,
                                            )}
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
                                        {formatBdt(
                                            selectedVariant?.effective_price ??
                                                product.price,
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Option picker. Each option carries its own stock,
                                so one being sold out says nothing about another. */}
                            {product.has_variants && variants.length > 0 && (
                                <div className="pdp-variants">
                                    <span className="pdp-variants-label">
                                        {(
                                            product.variant_attributes || []
                                        ).join(' / ') || 'Options'}
                                    </span>
                                    <div className="pdp-variant-options">
                                        {variants.map((variant) => {
                                            const out =
                                                variant.stock_quantity === 0;

                                            return (
                                                <button
                                                    key={variant.id}
                                                    type="button"
                                                    disabled={out}
                                                    className={`pdp-variant-chip ${
                                                        variant.id ===
                                                        selectedVariantId
                                                            ? 'is-selected'
                                                            : ''
                                                    } ${out ? 'is-out' : ''}`}
                                                    onClick={() => {
                                                        setSelectedVariantId(
                                                            variant.id,
                                                        );
                                                        setQuantity(1);
                                                        // Jump back to the
                                                        // first shot so the
                                                        // option's own image
                                                        // is what is showing.
                                                        setSelectedImageIndex(
                                                            0,
                                                        );
                                                    }}
                                                    title={
                                                        out
                                                            ? 'Out of stock'
                                                            : `${variant.stock_quantity} available`
                                                    }
                                                >
                                                    <span>{variant.name}</span>
                                                    <span className="pdp-variant-price">
                                                        {formatBdt(
                                                            variant.effective_price,
                                                        )}
                                                    </span>
                                                    {out && (
                                                        <span className="pdp-variant-out">
                                                            Sold out
                                                        </span>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {/* Key Features is authored markup — a curated list
                                that opens with the model and the part number.
                                short_description splitting on newlines was the
                                stand-in for it, and is still the fallback for
                                every product written before the field existed.
                                Sanitised server-side through RichText. */}
                            <div className="pdp-short-desc">
                                {product.key_features ? (
                                    <div
                                        className="pdp-key-features"
                                        dangerouslySetInnerHTML={{
                                            __html: product.key_features,
                                        }}
                                    />
                                ) : (
                                    <ul>
                                        {product.short_description
                                            ?.split('\n')
                                            .map((line, i) => (
                                                <li key={i}>{line}</li>
                                            )) || (
                                            <li>
                                                100% Genuine product with
                                                official brand warranty.
                                            </li>
                                        )}
                                    </ul>
                                )}
                            </div>

                            {/* Paying at once and paying monthly are different
                                prices, so they are shown as the choice they
                                are. The discount rewards paying now and the
                                instalment is on the regular price — presenting
                                one figure would misprice one of the two. */}
                            {(product.checkout_price <
                                product.effective_price ||
                                product.emi_monthly) && (
                                <div className="pdp-payment-options">
                                    <div className="pdp-pay-option active">
                                        <span className="pdp-pay-price">
                                            {formatBdt(product.checkout_price)}
                                        </span>
                                        {product.checkout_price <
                                            product.effective_price && (
                                            <span className="pdp-pay-tag">
                                                {formatBdt(
                                                    product.effective_price -
                                                        product.checkout_price,
                                                )}{' '}
                                                off on checkout
                                            </span>
                                        )}
                                        <span className="pdp-pay-note">
                                            Online / Cash payment
                                        </span>
                                    </div>

                                    {product.emi_monthly && (
                                        <div className="pdp-pay-option">
                                            <span className="pdp-pay-price">
                                                {formatBdt(product.emi_monthly)}
                                                /month
                                            </span>
                                            <span className="pdp-pay-tag">
                                                Regular price:{' '}
                                                {formatBdt(product.price)}
                                            </span>
                                            <span className="pdp-pay-note">
                                                0% EMI up to{' '}
                                                {product.emi_max_months} months
                                            </span>
                                        </div>
                                    )}
                                </div>
                            )}

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
                                        disabled={quantity >= availableStock}
                                        onClick={() =>
                                            setQuantity((prev) =>
                                                Math.min(
                                                    availableStock || 99,
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
                                    disabled={soldOut}
                                    onClick={handleBuyNow}
                                >
                                    {soldOut
                                        ? 'Out of Stock'
                                        : needsVariantChoice
                                          ? 'Choose an option'
                                          : isPreorder
                                            ? 'Pre-order Now'
                                            : 'Buy Now'}
                                </Button>
                                <Button
                                    variant={addedToCart ? 'dark' : 'secondary'}
                                    size="lg"
                                    disabled={soldOut}
                                    onClick={handleAddToCart}
                                    loading={addingToCart}
                                    icon={addedToCart ? Check : ShoppingCart}
                                >
                                    {soldOut
                                        ? 'Out of Stock'
                                        : addedToCart
                                          ? 'Added to Cart'
                                          : isPreorder
                                            ? 'Pre-order'
                                            : 'Add to Cart'}
                                </Button>
                            </div>

                            {/* Nobody should reach the payment page and only
                                then discover this ships later. */}
                            {isPreorder && (
                                <div
                                    className="pdp-preorder-notice"
                                    role="status"
                                >
                                    <Clock size={18} />
                                    <div>
                                        <strong>Pre-order</strong>
                                        <p>
                                            {releaseDate
                                                ? `This is out of stock now and expected back on ${releaseDate}. Order it today and it ships as soon as the delivery arrives.`
                                                : 'This is out of stock now. Order it today and it ships as soon as the next delivery arrives.'}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {/* Only when the thing being looked at is actually
                                unavailable — on a variant product that means
                                the chosen option, not the product overall. */}
                            {soldOut && (
                                <BackInStockForm
                                    productId={product.id}
                                    variantId={selectedVariant?.id ?? null}
                                />
                            )}

                            <BranchAvailability
                                productId={product.id}
                                variantId={selectedVariant?.id ?? null}
                            />

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

                    {/* Hand-picked suggestions. Hidden entirely when none are
                        set rather than falling back to category-mates: an
                        arbitrary product is worse than no suggestion, because
                        it looks deliberate. */}
                    {product.related_products?.length > 0 && (
                        <section className="pdp-related">
                            <h3>Similar Products</h3>
                            <div className="pdp-related-grid">
                                {product.related_products.map((item) => (
                                    <Link
                                        key={item.id}
                                        href={`/products/${item.slug}`}
                                        className="pdp-related-item"
                                    >
                                        <ProductImage
                                            product={item}
                                            className="pdp-related-img"
                                        />
                                        <span className="pdp-related-name">
                                            {item.name}
                                        </span>
                                        <span className="pdp-related-price">
                                            {formatBdt(
                                                item.effective_price ??
                                                    item.price,
                                            )}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}

                    {/* The question people actually type into Google, answered
                        on the page rather than left to a snippet generator.
                        Templated from the product's own figures, so it cannot
                        drift out of date the way a hand-written line would. */}
                    <section className="pdp-latest-price">
                        <h3>
                            What is the price of {product.name} in Bangladesh?
                        </h3>
                        <p>
                            The latest price of {product.name} in Bangladesh is{' '}
                            {formatBdt(
                                product.effective_price ?? product.price,
                            )}
                            . You can buy it at the best price from our website
                            or visit any of our showrooms.
                        </p>
                    </section>

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
                                    key: 'questions',
                                    label: 'Questions',
                                    badge: questions.length,
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
                            {activeTab === 'questions' && (
                                <ProductQuestions
                                    slug={productSlug}
                                    questions={questions}
                                    onAsked={loadQuestions}
                                />
                            )}

                            {activeTab === 'specifications' && (
                                <div className="specifications-table">
                                    <h3>Technical Specifications</h3>
                                    {product.specifications &&
                                    product.specifications.length > 0 ? (
                                        <table>
                                            {/* Grouped into sections, in the order
                                                the admin entered them. A product
                                                whose specs predate grouping has no
                                                `group` on any row and renders as
                                                the plain two-column table it
                                                always was. */}
                                            {groupSpecifications(
                                                product.specifications,
                                            ).map(({ group, items }) => (
                                                <tbody key={group || '__none'}>
                                                    {group && (
                                                        <tr className="spec-group-row">
                                                            <th
                                                                colSpan={2}
                                                                scope="colgroup"
                                                                className="spec-group"
                                                            >
                                                                {group}
                                                            </th>
                                                        </tr>
                                                    )}
                                                    {items.map((spec) => (
                                                        <tr key={spec.id}>
                                                            <td className="spec-name">
                                                                {spec.name}
                                                            </td>
                                                            <td className="spec-value">
                                                                {spec.value}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            ))}
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
                                                    {siteConfig.name} can write
                                                    a review.{' '}
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
                                                    from {siteConfig.name} can
                                                    submit a review.
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
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
ProductDetails.layout = mainLayout;
