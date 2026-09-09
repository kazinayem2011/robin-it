import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import {
    productService,
    cartService,
    compareService,
    reviewService,
} from '@/services';
import BackInStockForm from '../../Components/BackInStockForm';
import ProductSuggestions from '../../Components/ProductSuggestions';
import Button from '../../Components/Button';
import CountdownTimer from '../../Components/CountdownTimer';
// The gallery renders <ProductImage> but never imported it, so the whole page
// threw "ProductImage is not defined" and rendered nothing at all.
import ProductImage from '../../Components/ProductImage';
import ProductDescription from '../../Components/ProductDescription';
import ProductQuestions from '../../Components/ProductQuestions';
import ProductSpecifications from '../../Components/ProductSpecifications';
import ProductWarranty from '../../Components/ProductWarranty';
import RatingBreakdown from '../../Components/RatingBreakdown';
import ReviewForm from '../../Components/ReviewForm';
import ReviewList from '../../Components/ReviewList';
import SEOHead from '../../Components/SEOHead';
import { ProductDetailSkeleton } from '../../Components/Skeleton';
import Tabs from '../../Components/Tabs';
import { toast } from '../../Components/Toast';
import useAppStore from '../../store/useAppStore';
import { useWishlist } from '../../hooks';
import { formatBdt } from '../../utils/formatters';
import { stockStatusFor } from '../../utils/stockStatus';
import { productSchemaFor } from '../../utils/productSchema';
import { FacebookGlyph, WhatsAppGlyph } from '../../Components/BrandGlyphs';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import {
    ShoppingCart,
    Check,
    Clock,
    ShieldCheck,
    Bookmark,
    SquarePlus,
    Link2,
} from 'lucide-react';
import './Show.css';

export default function ProductDetails(props) {
    /* Shared by Inertia on every page, so a signed-in shopper is not asked
       for a name the shop already has. */
    const { auth, brand_name: brandName } = usePage().props;

    /*
     * A signed-in customer may have registered with a mobile number instead of
     * an email address. A guest is asked for one, so they can always be told.
     */
    const canBeEmailed = !auth?.user || Boolean(auth.user.email);

    /*
     * The shared hook, not a handler of this page's own. It loads what is
     * already saved, so a returning customer sees a filled heart instead of an
     * empty one, and it keeps the header count in step. The page used to
     * toggle and show a toast while the button itself never changed.
     */
    const { wishlistIds, toggleWishlist, pendingId } = useWishlist();

    const productSlug =
        props.productSlug ||
        props.slug ||
        (typeof window !== 'undefined'
            ? window.location.pathname.split('/').filter(Boolean).pop()
            : '');

    const [product, setProduct] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [activeSection, setActiveSection] = useState('specification');

    /*
     * Which of the two payment options is selected. Presentational for now —
     * both routes end at the same checkout, where the method is chosen again
     * against whatever the gateway offers on the day. It is a radio because it
     * reads as a choice and one of them is cheaper, so which is selected has
     * to be visible rather than implied by shading.
     */
    const [payMethod, setPayMethod] = useState('cash');
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

    /*
     * The two figures the page quotes: what this costs paid outright, and the
     * list price it is measured against.
     *
     * `checkout_price` is the cash-and-online figure and the one headed
     * "Price", the same way the shop's own paperwork reads. It is a product
     * field rather than a variant one, so an option falls back to its own
     * effective price instead of quoting the parent's discount for a different
     * option. Regular is the undiscounted list price, and is only ever shown
     * when it is actually higher.
     */
    const priced = selectedVariant ?? product;
    const cashPrice =
        (selectedVariant ? null : product?.checkout_price) ??
        priced?.effective_price ??
        priced?.price ??
        0;
    const regularPrice = priced?.price ?? 0;

    /*
     * The availability line, and whether it reads as good news. Worked out in
     * one place because a variant product's own label describes the total
     * across its options, not the option in front of the shopper.
     */
    const stockStatus = stockStatusFor(product, {
        selectedVariant,
        availableStock,
    });

    /*
     * The address to share, without the query string — the same rule the
     * canonical tag follows. `?sort=`, `?page=` and whatever a previous share
     * appended are not part of the product, and passing them on means the
     * next person's share carries them too.
     *
     * Read at render rather than kept in state, because an Inertia navigation
     * changes it without this component unmounting. Guarded because the module
     * is evaluated before the browser exists.
     *
     * Note for local testing: Facebook builds its preview by fetching the URL
     * it is given, so from localhost the composer opens with nothing attached.
     * That is Facebook being unable to reach the address, not a missing link —
     * it fills in once the URL is publicly reachable.
     */
    const pageUrl =
        typeof window !== 'undefined'
            ? window.location.origin + window.location.pathname
            : '';

    const copyProductLink = async () => {
        try {
            await navigator.clipboard.writeText(pageUrl);
            toast.success('Product link copied to clipboard!');
        } catch {
            // Clipboard access needs a secure context and the viewer's
            // permission, and neither is guaranteed. Saying so beats a button
            // that appears to do nothing.
            toast.error(
                'Could not copy the link. Copy it from the address bar.',
            );
        }
    };

    /*
     * The sections below the product, and the row that navigates them. Four
     * always, plus Warranty on a product that records one.
     *
     * One ref each rather than one for the group, because the row has to be
     * able to scroll to any of them and to mark whichever is being read.
     */
    const sectionsRef = useRef(null);

    /*
     * Whether there is a warranty to show. Both fields are optional and most
     * of the catalogue carries neither, so the section and its place in the
     * row above appear together or not at all.
     */
    const hasWarranty = Boolean(
        Number(product?.warranty_months) ||
        (product?.warranty_text || '').trim(),
    );

    const sectionRefs = {
        specification: useRef(null),
        description: useRef(null),
        warranty: useRef(null),
        questions: useRef(null),
        reviews: useRef(null),
    };

    const goToSection = (key) => {
        setActiveSection(key);
        sectionRefs[key]?.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    };

    /* "View More Info" is the same journey, to the first of them. */
    const showFullDetails = () => goToSection('specification');

    /*
     * How tall the navigation row is, published for the CSS to offset against.
     *
     * A section has to come to rest below the site header *and* below this row,
     * or its heading arrives hidden behind them. Measured rather than assumed,
     * the way Header.jsx measures its own bars, because the row's height moves
     * with the type scale and wraps on a narrow screen.
     */
    useEffect(() => {
        const container = sectionsRef.current;

        if (!container || typeof ResizeObserver === 'undefined') {
            return;
        }

        const row = container.querySelector('.pdp-section-nav');

        if (!row) {
            return;
        }

        const publish = () => {
            document.documentElement.style.setProperty(
                '--pdp-nav-h',
                `${Math.round(row.getBoundingClientRect().height)}px`,
            );
        };

        publish();

        const observer = new ResizeObserver(publish);
        observer.observe(row);

        return () => observer.disconnect();
    }, [product]);

    /*
     * Keep the row honest as the page is scrolled by hand.
     *
     * Without this the row marks whatever was last clicked, so it can claim
     * the reader is in Specification while they are reading Reviews — worse
     * than marking nothing, because it is confidently wrong.
     *
     * The top of the band is the header plus the row, the same offset the
     * sections rest at, so clicking a section and scrolling to it agree about
     * which one is current. Ignoring the bottom 55% means a section counts as
     * being read once its heading reaches the upper part of the screen, rather
     * than the moment a pixel of it appears at the bottom.
     */
    useEffect(() => {
        if (!product || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const nodes = Object.entries(sectionRefs)
            .map(([key, ref]) => [key, ref.current])
            .filter(([, node]) => node);

        if (!nodes.length) {
            return;
        }

        const px = (name, fallback) => {
            const raw = getComputedStyle(
                document.documentElement,
            ).getPropertyValue(name);

            return parseInt(raw, 10) || fallback;
        };

        const top = px('--site-chrome-h', 142) + px('--pdp-nav-h', 48) + 12;

        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort(
                        (a, b) =>
                            a.boundingClientRect.top - b.boundingClientRect.top,
                    )[0];

                if (!visible) {
                    return;
                }

                const match = nodes.find(([, node]) => node === visible.target);

                if (match) {
                    setActiveSection(match[0]);
                }
            },
            { rootMargin: `-${top}px 0px -55% 0px`, threshold: 0 },
        );

        nodes.forEach(([, node]) => observer.observe(node));

        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [product]);

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

    const isWishlisted = wishlistIds.includes(product.id);

    /* related_products is still read as a fallback, for any caller that has
       not been given the worked-out list. */
    const similar = product.similar_products ?? product.related_products ?? [];

    const productImages =
        product.images && product.images.length > 0
            ? product.images.map((img) => img.image_path)
            : [
                  siteConfig.productPlaceholder ||
                      '/images/product-placeholder.svg',
              ];

    /*
     * An option carries its own photos — a white card looks nothing like the
     * black one. They lead the gallery so the pictures match what is selected,
     * with the product's own still available behind them.
     *
     * image_url is the option's lead shot and is kept in step with the first of
     * its photos, so it is the fallback for an option photographed before
     * options had galleries.
     */
    const variantImages = selectedVariant
        ? (selectedVariant.images?.length
              ? selectedVariant.images.map((img) => img.image_path)
              : [selectedVariant.image_url]
          ).filter(Boolean)
        : [];

    const images = variantImages.length
        ? [
              ...variantImages,
              ...productImages.filter((i) => !variantImages.includes(i)),
          ]
        : productImages;

    const schemaData = productSchemaFor(product, {
        price: cashPrice,
        image: images[0],
        inStock: stockStatus.tone === 'in',
        sellerName: brandName || siteConfig.name,
        reviews: reviewsData,
    });

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
                {/*
                    Sharing it, keeping it, and lining it up against something
                    else — the three things somebody does with a product other
                    than buy it. They belong together and above the fold, which
                    is where the reference puts them; Save and Compare used to
                    be at the very bottom of the summary column, below the
                    branch stock table, where they were reached by scrolling
                    past everything that matters.
                */}
                <div className="pdp-utility-bar">
                    <div className="pdp-share">
                        <span className="pdp-share-label">Share:</span>

                        <a
                            className="pdp-share-btn is-facebook"
                            href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(pageUrl)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Share on Facebook"
                            title="Share on Facebook"
                        >
                            <FacebookGlyph size={20} />
                        </a>

                        <a
                            className="pdp-share-btn is-whatsapp"
                            href={`https://wa.me/?text=${encodeURIComponent(`${product.name} — ${pageUrl}`)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Share on WhatsApp"
                            title="Share on WhatsApp"
                        >
                            <WhatsAppGlyph size={20} />
                        </a>

                        <button
                            type="button"
                            className="pdp-share-btn is-link"
                            onClick={copyProductLink}
                            aria-label="Copy link to this product"
                            title="Copy link"
                        >
                            <Link2 size={15} />
                        </button>
                    </div>

                    <div className="pdp-utility-actions">
                        <button
                            type="button"
                            className={`pdp-utility-btn ${isWishlisted ? 'is-saved' : ''}`}
                            onClick={() => toggleWishlist(product.id)}
                            disabled={pendingId === product.id}
                            aria-pressed={isWishlisted}
                        >
                            <Bookmark
                                size={16}
                                fill={isWishlisted ? 'currentColor' : 'none'}
                            />
                            {isWishlisted ? 'Saved' : 'Save'}
                        </button>

                        <button
                            type="button"
                            className="pdp-utility-btn"
                            onClick={handleAddToCompare}
                        >
                            <SquarePlus size={16} /> Add to Compare
                        </button>
                    </div>
                </div>
                {/* Top Section: Image & Basic Info */}
                <div className="pdp-top">
                    {/* Image Gallery */}
                    <div className="pdp-gallery">
                        <div className="main-image">
                            <ProductImage
                                src={images[selectedImageIndex] || images[0]}
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

                        {/* One row of facts, in the order a shopper checks
                            them: what it costs, what it usually costs,
                            whether it can be had, what to quote on the
                            phone, who makes it.

                            The price leads here rather than sitting in its
                            own block underneath, because it is the first
                            thing being looked for and the Payment Options
                            below already carry it at size. Two places, not
                            three. */}
                        <div className="pdp-meta">
                            <div className="meta-item">
                                <span className="meta-label">Price:</span>
                                <span className="meta-value">
                                    {formatBdt(cashPrice)}
                                </span>
                            </div>

                            {/* Only when it is genuinely a different
                                number. "Regular Price" repeating the price
                                beside it reads as a mistake. */}
                            {regularPrice > cashPrice && (
                                <div className="meta-item">
                                    <span className="meta-label">
                                        Regular Price:
                                    </span>
                                    <span className="meta-value">
                                        {formatBdt(regularPrice)}
                                    </span>
                                </div>
                            )}

                            <div className="meta-item">
                                <span className="meta-label">Status:</span>
                                <span
                                    className={`meta-value is-stock-${stockStatus.tone}`}
                                >
                                    {stockStatus.label}
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

                            {/* Only when there is one. "Brand: N/A" is a
                                chip that answers nothing and pushes the
                                ones that do along. */}
                            {product.brand?.name && (
                                <div className="meta-item">
                                    <span className="meta-label">Brand:</span>
                                    <span className="meta-value">
                                        {product.brand.name}
                                    </span>
                                </div>
                            )}
                        </div>

                        {/* The clock stays with the deal it is counting
                            down, now that the price it belonged to has
                            moved up into the row above. */}
                        {(selectedVariant ?? product).has_discount && (
                            <div className="pdp-deal-clock">
                                <CountdownTimer
                                    label="LIMITED DEAL:"
                                    variant="pill"
                                    showIcon={true}
                                    iconType="flame"
                                />
                            </div>
                        )}

                        {/* Option picker. Each option carries its own stock,
                            so one being sold out says nothing about another. */}
                        {product.has_variants && variants.length > 0 && (
                            <div className="pdp-variants">
                                <span className="pdp-variants-label">
                                    {(product.variant_attributes || []).join(
                                        ' / ',
                                    ) || 'Options'}
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
                                                    setSelectedImageIndex(0);
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
                            <h2 className="pdp-section-heading">
                                Key Features
                            </h2>

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
                                            100% Genuine product with official
                                            brand warranty.
                                        </li>
                                    )}
                                </ul>
                            )}

                            {/* The summary above is the headline; the full
                                table is a long way down the page past the
                                suggestions. This carries the reader there
                                and opens the right panel, rather than
                                leaving them to scroll and then find the
                                tab still on whatever they last touched.
                                Specifications when there are any, the
                                description when there are not — landing on
                                an empty table is worse than not offering
                                the jump. */}
                            <button
                                type="button"
                                className="pdp-more-info"
                                onClick={showFullDetails}
                            >
                                View More Info
                            </button>
                        </div>

                        {/* Buy-more-pay-less, shown as a table rather than
                            buried in the description. A trade buyer who
                            cannot see the tier phones instead of ordering,
                            which is the problem this solves. */}
                        {product.quantity_discounts?.length > 0 && (
                            <div className="pdp-tier-table">
                                <h4>Bulk pricing</h4>
                                <table>
                                    <tbody>
                                        {product.quantity_discounts.map(
                                            (tier) => (
                                                <tr key={tier.id}>
                                                    <td>
                                                        {tier.min_quantity}+
                                                        units
                                                    </td>
                                                    <td>
                                                        {formatBdt(tier.price)}{' '}
                                                        each
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Paying at once and paying monthly are different
                            prices, so they are shown as the choice they
                            are. The discount rewards paying now and the
                            instalment is on the regular price — presenting
                            one figure would misprice one of the two. */}
                        <h2 className="pdp-section-heading">Payment Options</h2>

                        <div
                            className="pdp-payment-options"
                            role="radiogroup"
                            aria-label="Payment Options"
                        >
                            {/* Always drawn, even with nothing to compare
                                it against: this is where the price is
                                shown at size, so a product with no
                                instalment plan would otherwise have none. */}
                            <label
                                className={`pdp-pay-option ${payMethod === 'cash' ? 'active' : ''}`}
                            >
                                <input
                                    type="radio"
                                    name="pdp-pay"
                                    value="cash"
                                    checked={payMethod === 'cash'}
                                    onChange={() => setPayMethod('cash')}
                                />
                                <span className="pdp-pay-body">
                                    <span className="pdp-pay-price">
                                        {formatBdt(cashPrice)}
                                    </span>
                                    <span className="pdp-pay-tag">
                                        Cash Discount Price
                                    </span>
                                    <span className="pdp-pay-note">
                                        Online / Cash Payment
                                    </span>
                                </span>
                            </label>

                            {product.emi_monthly && (
                                <label
                                    className={`pdp-pay-option ${payMethod === 'emi' ? 'active' : ''}`}
                                >
                                    <input
                                        type="radio"
                                        name="pdp-pay"
                                        value="emi"
                                        checked={payMethod === 'emi'}
                                        onChange={() => setPayMethod('emi')}
                                    />
                                    <span className="pdp-pay-body">
                                        <span className="pdp-pay-price">
                                            {formatBdt(product.emi_monthly)}
                                            /month
                                        </span>
                                        <span className="pdp-pay-tag">
                                            Regular Price:{' '}
                                            {formatBdt(product.price)}
                                        </span>
                                        <span className="pdp-pay-note">
                                            0% EMI for up to{' '}
                                            {product.emi_max_months} Months ***
                                        </span>
                                    </span>
                                </label>
                            )}
                        </div>

                        <div className="pdp-actions">
                            {/*
                             * Sold out is one state, so it gets one
                             * control. "Buy Now" and "Add to Cart" both
                             * fall back to the same label when there is
                             * nothing to sell, which put two identical
                             * dead buttons side by side — and a quantity
                             * stepper above them for choosing how many of
                             * nothing to have. What a shopper can
                             * actually do next is the waiting list below.
                             */}
                            {soldOut ? (
                                <Button variant="secondary" size="lg" disabled>
                                    Out of Stock
                                </Button>
                            ) : (
                                <>
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
                                                quantity >= availableStock
                                            }
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
                                        {needsVariantChoice
                                            ? 'Choose an option'
                                            : isPreorder
                                              ? 'Pre-order Now'
                                              : 'Buy Now'}
                                    </Button>
                                    <Button
                                        variant={
                                            addedToCart ? 'dark' : 'secondary'
                                        }
                                        size="lg"
                                        disabled={soldOut}
                                        onClick={handleAddToCart}
                                        loading={addingToCart}
                                        icon={
                                            addedToCart ? Check : ShoppingCart
                                        }
                                    >
                                        {addedToCart
                                            ? 'Added to Cart'
                                            : isPreorder
                                              ? 'Pre-order'
                                              : 'Add to Cart'}
                                    </Button>
                                </>
                            )}
                        </div>

                        {/* Nobody should reach the payment page and only
                            then discover this ships later. */}
                        {isPreorder && (
                            <div className="pdp-preorder-notice" role="status">
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
                            the chosen option, not the product overall.

                            And only when there is somewhere to write to.
                            An account can be opened with a mobile number
                            and no address; this waiting list is email, so
                            for those customers it is not an offer at all
                            and asking would be a form they cannot use. */}
                        {soldOut && canBeEmailed && (
                            <BackInStockForm
                                productId={product.id}
                                variantId={selectedVariant?.id ?? null}
                                accountEmail={auth?.user?.email ?? ''}
                            />
                        )}
                    </div>
                </div>
                {/*
                 * Everything the shop knows about this product, on one page,
                 * with the row above it as the way around.
                 *
                 * The row is not switching panels any more — all four are
                 * rendered and a click scrolls. That is what the reference
                 * does, and it means a shopper checking a figure in the table
                 * against a sentence in the description keeps their place
                 * instead of clicking between two views of the same product.
                 *
                 * Each section's id matches its key in the row, so the links
                 * are ordinary fragment links and work without the scrolling.
                 */}
                <div
                    className="pdp-sections"
                    id="product-details"
                    ref={sectionsRef}
                >
                    <Tabs
                        navigation
                        tabs={[
                            {
                                key: 'specification',
                                label: 'Specification',
                            },
                            {
                                key: 'description',
                                label: 'Description',
                            },
                            /*
                             * Only when the product has one. Most of this
                             * catalogue carries no warranty terms, and a
                             * permanent tab leading to "nothing recorded"
                             * advertises that absence on every product.
                             */
                            ...(hasWarranty
                                ? [{ key: 'warranty', label: 'Warranty' }]
                                : []),
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
                        activeTab={activeSection}
                        onChange={goToSection}
                        variant="blocks"
                        className="pdp-section-nav"
                    />

                    <div className="pdp-sections-body">
                        <div className="pdp-sections-main">
                            <section
                                id="specification"
                                className="pdp-section"
                                ref={sectionRefs.specification}
                            >
                                <h2 className="pdp-details-heading">
                                    Specification
                                </h2>

                                <ProductSpecifications
                                    specifications={
                                        product.specifications || []
                                    }
                                />
                            </section>

                            <section
                                id="description"
                                className="pdp-section"
                                ref={sectionRefs.description}
                            >
                                <h2 className="pdp-details-heading">
                                    Description
                                </h2>

                                <ProductDescription
                                    description={product.description}
                                />
                            </section>

                            {hasWarranty && (
                                <section
                                    id="warranty"
                                    className="pdp-section"
                                    ref={sectionRefs.warranty}
                                >
                                    <h2 className="pdp-details-heading">
                                        Warranty
                                    </h2>

                                    <ProductWarranty
                                        months={product.warranty_months}
                                        terms={product.warranty_text}
                                    />
                                </section>
                            )}

                            <section
                                id="questions"
                                className="pdp-section"
                                ref={sectionRefs.questions}
                            >
                                <h2 className="pdp-details-heading">
                                    Questions
                                </h2>

                                {/*
                                 * The one question the shop can always answer, above
                                 * the ones customers have asked.
                                 *
                                 * It used to be a paragraph of its own at the foot of
                                 * the page, written as prose for a search engine to
                                 * interpret. The price is published properly in the
                                 * Product markup now, so this is here for the reader
                                 * instead — in the place someone looking for an answer
                                 * goes, and in the same shape as every other answer.
                                 *
                                 * Templated from the product's own figures and marked
                                 * up like a real entry, but it is not one: it has no
                                 * row behind it, so it cannot be edited or removed in
                                 * admin, and it is not counted in "N questions".
                                 */}
                                <ul className="pdp-question-list pdp-question-list-standing">
                                    <li className="pdp-question">
                                        <div className="pdp-qa-row">
                                            <span className="pdp-q-marker">
                                                Q
                                            </span>
                                            <div className="pdp-qa-body">
                                                <p className="pdp-question-text">
                                                    What is the price of{' '}
                                                    {product.name} in
                                                    Bangladesh?
                                                </p>
                                            </div>
                                        </div>
                                        <div className="pdp-qa-row is-answer">
                                            <span className="pdp-a-marker">
                                                A
                                            </span>
                                            <div className="pdp-qa-body">
                                                <p className="pdp-answer-text">
                                                    The latest price is{' '}
                                                    {formatBdt(cashPrice)}
                                                    {selectedVariant && (
                                                        <>
                                                            {' '}
                                                            for the{' '}
                                                            {Object.values(
                                                                selectedVariant.options ||
                                                                    {},
                                                            ).join(' / ')}{' '}
                                                            option
                                                        </>
                                                    )}
                                                    . You can buy it from our
                                                    website or visit any of our
                                                    showrooms.
                                                </p>
                                                <span className="pdp-question-meta">
                                                    {brandName}
                                                </span>
                                            </div>
                                        </div>
                                    </li>
                                </ul>

                                <ProductQuestions
                                    slug={productSlug}
                                    questions={questions}
                                    onAsked={loadQuestions}
                                    askingAs={auth?.user?.name || ''}
                                />
                            </section>

                            <section
                                id="reviews"
                                className="pdp-section"
                                ref={sectionRefs.reviews}
                            >
                                <h2 className="pdp-details-heading">
                                    Ratings &amp; Reviews
                                </h2>

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
                                                            color: 'var(--primary-ink)',
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
                            </section>
                        </div>

                        {/*
                         * Beside the detail rather than in a band under it.
                         *
                         * Hand-picked where a shopkeeper has chosen them,
                         * worked out from the same shelf where nobody has. It
                         * used to be hand-picked only, and nothing had been
                         * picked for any of the shop's twelve hundred products
                         * — so a shopper looking at a mouse was never offered
                         * another mouse.
                         */}
                        <aside className="pdp-sections-aside">
                            <ProductSuggestions
                                products={similar}
                                title="You might also like"
                                layout="column"
                                className="pdp-rail-suggestions"
                            />
                        </aside>
                    </div>
                </div>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
ProductDetails.layout = mainLayout;
