import React, { useEffect, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import Modal from './Modal';
import Button from './Button';
import ProductImage from './ProductImage';
import { cartService, productService } from '../services';
import useAppStore from '../store/useAppStore';
import { toast } from './Toast';
import { formatBdt } from '../utils/formatters';
import { boundsFor } from '../utils/cartBounds';
import { ROUTES } from '../constants/endpoints';
import { productSummary, shortSummary } from '../utils/productSummary';
import { Bell, ShoppingCart, Plus, Minus } from 'lucide-react';

/*
 * What Quick View has fetched this visit, by slug. Only the words are read
 * from it — price and stock come from the card, which is current — so an
 * entry going a little stale during one visit cannot mislead anyone.
 */
const detailsCache = new Map();

/** For tests: each starts with nothing fetched. */
export function forgetQuickViewDetails() {
    detailsCache.clear();
}

export default function QuickViewModal({ show, onClose, product }) {
    // Hooks first, then the early return — see ProductCard for why. This modal
    // is mounted with product=null until a card is clicked, which is exactly
    // the sequence that breaks.
    const [quantity, setQuantity] = useState(1);
    const [adding, setAdding] = useState(false);

    /*
     * The product's own words, fetched when the panel opens.
     *
     * A card carries its name, price and key features, but no description —
     * so the panel fell back to one fixed line of marketing, the same under
     * every product in the shop. The summary is read from the product page's
     * own data instead; everything the card already knew is shown at once.
     */
    const slug = product?.slug;
    // Kept per slug for the visit, so opening the same product again shows
    // it at once instead of fetching it and flashing the loading lines.
    const [fetched, setFetched] = useState(() =>
        slug && detailsCache.has(slug) ? detailsCache.get(slug) : undefined,
    );
    const details = fetched && fetched.slug === slug ? fetched.data : null;
    const loadingDetails =
        Boolean(show && slug) && !details && !fetched?.failed;

    useEffect(() => {
        if (!show || !slug) return undefined;
        if (detailsCache.has(slug)) {
            setFetched(detailsCache.get(slug));
            return undefined;
        }

        let cancelled = false;

        productService
            .getProductBySlug(slug)
            .then((res) => {
                const entry = { slug, data: res ?? null, failed: !res };
                if (res) detailsCache.set(slug, entry);
                if (!cancelled) setFetched(entry);
            })
            .catch(() => {
                // Without it the panel is the card's facts, which is enough.
                if (!cancelled) setFetched({ slug, data: null, failed: true });
            });

        return () => {
            cancelled = true;
        };
    }, [show, slug]);

    /*
     * Parsed once per product, not on every render: a quantity click
     * re-renders the panel, and the description is a page of markup.
     */
    const lead = useMemo(() => shortSummary(details), [details]);
    const written = useMemo(() => productSummary(details), [details]);

    if (!product) return null;

    /*
     * A card sends its prices formatted ("৳77,000") with the numbers beside
     * them, and the old price under its own name; reading only discount_price
     * meant a discounted product never showed what it was reduced from.
     */
    const current = Number(
        product.raw_price ?? product.discount_price ?? product.price ?? 0,
    );
    const original = Number(product.raw_old_price ?? product.price ?? 0);
    const price = current || product.price;
    const hasDiscount =
        Boolean(product.oldPrice ?? product.discount_price) &&
        original > current;

    const keyFeatures = (product.specs ?? []).slice(0, 4);
    /*
     * The shop's own short summary ("Short Summary / Key Highlights" on the
     * product form), then a few sentences of the description.
     *
     * Not the same words twice: a product with no key features has its short
     * description as its one feature line, and one with no description has it
     * as the summary too — each was printed again underneath the other.
     */
    const plain = (text) => String(text).replace(/\s+/g, ' ').trim();
    const shown = keyFeatures.map(plain);
    const leadShown = shown.includes(plain(lead)) ? '' : lead;
    const summary =
        shown.includes(plain(written)) || plain(written) === plain(leadShown)
            ? ''
            : written;

    const hasOptions = Boolean(product.has_variants ?? product.hasVariants);

    /* Read exactly as ProductCard reads it. The card is what was clicked to
       get here, and a card offering "Add to cart" over a panel saying "Out of
       stock" is worse than either answer on its own. */
    const inStock =
        product.inStock !== undefined
            ? Boolean(product.inStock)
            : Number(product.stock_quantity ?? 0) > 0;
    const isPreorder = Boolean(
        product.preorder ?? product.is_preorder ?? false,
    );
    const canBuy = hasOptions || inStock || isPreorder;
    const soldOutLabel = product.out_of_stock_status || 'Sold Out';

    /* One line on whether it can be had, in the card's colours. */
    let status = { label: 'In Stock', tone: 'in' };
    if (!inStock && isPreorder)
        status = { label: 'Pre-order', tone: 'preorder' };
    else if (!inStock && !hasOptions)
        status = { label: soldOutLabel, tone: 'out' };

    /* The same ceiling the cart and checkout use, so this cannot offer a
       quantity the next request refuses. An option product is bounded by the
       option, which is not known until one is picked. */
    const { min, max } = boundsFor({ product, variant: null }, null);

    const handleAddToCart = async () => {
        /*
         * A product sold by option cannot be added from here either — the
         * server is asked for a product and an option and refuses a product
         * alone. Quick view used to send the request anyway and report
         * "Failed to add product to cart", which is what made this look
         * broken rather than incomplete.
         */
        if (hasOptions) {
            // One option is not a question; add the default and say which.
            const onlyOne =
                product.variant_count === 1 && product.default_variant_id;

            if (!onlyOne) {
                onClose();
                useAppStore.getState().openVariantPicker({
                    slug: product.slug,
                    name: product.name,
                    thenCheckout: false,
                });

                return;
            }
        }

        setAdding(true);
        try {
            await cartService.addToCart(
                product.id,
                quantity,
                // Set only for a product whose single option is the
                // default; anything with a real choice went to the
                // picker above.
                hasOptions ? product.default_variant_id : null,
            );
            useAppStore.getState().fetchCartCount();
            toast.success(
                `Added ${quantity}x "${product.name}" to cart!`,
                'Cart Updated',
            );
            onClose();
        } catch (error) {
            console.error('Failed to add to cart', error);
            /*
             * The server's own words. It says which option is needed, or how
             * many are left — and all of that was being thrown away for one
             * sentence that told the shopper nothing they could act on.
             */
            toast.error(
                error?.message || 'Failed to add product to cart.',
                'Error',
            );
        } finally {
            setAdding(false);
        }
    };

    return (
        // Modal takes `isOpen` and a CSS width. It was being given `show` and
        // "2xl", so isOpen defaulted to false and Quick View never opened at
        // all — which is why "View Details" was unreachable.
        <Modal
            isOpen={show}
            onClose={onClose}
            title="Quick view"
            maxWidth="820px"
        >
            <div className="quick-view-grid">
                {/* Left: Product Image */}
                <div className="quick-view-image-box">
                    <ProductImage
                        product={product}
                        alt={product.name}
                        className="quick-view-img"
                    />
                </div>

                {/* Right: Info & Actions */}
                <div className="quick-view-info-box">
                    <div>
                        {/* Was 'Authorized Hardware' when no brand was
                            recorded — see the note in ProductCard. */}
                        {product.brand?.name && (
                            <span className="quick-view-brand">
                                {product.brand.name}
                            </span>
                        )}
                        <h3 className="quick-view-title">{product.name}</h3>

                        {/* No price once it cannot be bought, as on the card. */}
                        <div className="quick-view-price-stack">
                            {canBuy && (
                                <>
                                    <span className="quick-view-current-price">
                                        {formatBdt(price)}
                                    </span>
                                    {hasDiscount && (
                                        <span className="quick-view-old-price">
                                            {formatBdt(original)}
                                        </span>
                                    )}
                                </>
                            )}
                            <span
                                className={`quick-view-status is-${status.tone}`}
                            >
                                {status.label}
                            </span>
                        </div>

                        {/* The card's key features: processor, memory,
                            display — what a shopper opens this to check. */}
                        {keyFeatures.length > 0 && (
                            <ul className="product-specs-list quick-view-specs">
                                {keyFeatures.map((line, idx) => (
                                    <li key={idx}>{line}</li>
                                ))}
                            </ul>
                        )}

                        {leadShown && (
                            <p className="quick-view-lead">{leadShown}</p>
                        )}

                        {/* A few sentences of the description; nothing at
                            all rather than words that fit any product. */}
                        {loadingDetails && !summary ? (
                            <div
                                className="quick-view-desc-loading"
                                aria-hidden="true"
                            >
                                <span className="skeleton-shimmer" />
                                <span className="skeleton-shimmer" />
                                <span className="skeleton-shimmer" />
                            </div>
                        ) : (
                            summary && (
                                <p className="quick-view-desc">{summary}</p>
                            )
                        )}
                    </div>

                    {/* Footer Quantity & CTA */}
                    <div>
                        {!hasOptions && canBuy && (
                            <div className="quick-view-qty-row">
                                <span className="quick-view-qty-label">
                                    Quantity:
                                </span>
                                <div className="quick-view-qty-control">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setQuantity((q) =>
                                                Math.max(min, q - 1),
                                            )
                                        }
                                        disabled={quantity <= min}
                                        className="quick-view-qty-btn"
                                        aria-label="Decrease quantity"
                                    >
                                        <Minus size={14} />
                                    </button>
                                    <span className="quick-view-qty-val">
                                        {quantity}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setQuantity((q) =>
                                                Math.min(max, q + 1),
                                            )
                                        }
                                        disabled={quantity >= max}
                                        title={
                                            quantity >= max
                                                ? `Only ${max} available`
                                                : undefined
                                        }
                                        className="quick-view-qty-btn"
                                        aria-label="Increase quantity"
                                    >
                                        <Plus size={14} />
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="quick-view-cta-row">
                            {/* Sold out: the status above says so, and this
                                is the next step — the product page's
                                back-in-stock form, as the card offers. */}
                            {!canBuy ? (
                                <Link
                                    href={`${ROUTES.PRODUCT_DETAIL(product.slug)}#notify`}
                                    className="btn btn-primary btn-md btn-full quick-view-notify-btn"
                                >
                                    <Bell size={16} />
                                    Notify me
                                </Link>
                            ) : (
                                <Button
                                    variant="primary"
                                    size="md"
                                    fullWidth
                                    icon={ShoppingCart}
                                    loading={adding}
                                    onClick={handleAddToCart}
                                    className={
                                        isPreorder && !hasOptions
                                            ? 'btn-preorder'
                                            : ''
                                    }
                                >
                                    {hasOptions
                                        ? 'Choose options'
                                        : isPreorder
                                          ? 'Pre-order'
                                          : 'Add to Cart'}
                                </Button>
                            )}
                            <Link
                                href={ROUTES.PRODUCT_DETAIL(product.slug)}
                                className="btn btn-secondary btn-md quick-view-details-btn"
                            >
                                View Details
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    );
}
