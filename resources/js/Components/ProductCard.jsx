import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Bell,
    Scale,
    ShoppingCart,
    Heart,
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
    const [buying, setBuying] = useState(false);

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

    const handleAddToCart = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (onAddToCart) {
            onAddToCart(product);
        }
    };

    /*
     * The card already offers add-to-cart on the icon rail, so the button does
     * the other thing: it adds and takes the shopper to checkout. The label was
     * "Buy" once before while only adding to the cart, which reads as going to
     * checkout and does not — so this only navigates when the product actually
     * reached the cart. A product with options raises the picker first, and
     * that carries on to checkout once one has been chosen.
     */
    const handleBuyNow = async (e) => {
        e.preventDefault();
        e.stopPropagation();

        if (!onAddToCart || buying) return;

        setBuying(true);

        try {
            /*
             * A product with options returns false here and opens the picker
             * instead — nothing is in the cart yet, so there is nowhere to go.
             * The flag tells the picker that checkout is where this was
             * heading, so it can finish the journey once an option is chosen.
             */
            const added = await Promise.resolve(
                onAddToCart(product, { thenCheckout: true }),
            );

            if (added) router.visit(ROUTES.CHECKOUT);
        } finally {
            setBuying(false);
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

    // A product with options cannot be added blind — the handler raises a
    // picker for those, so the control asks rather than promising a cart.
    const hasOptions = Boolean(product.has_variants ?? product.hasVariants);

    /*
     * Sold out and available to pre-order look identical from the stock number
     * alone, and they are not the same offer. A pre-order can still be bought;
     * it just ships when the delivery lands.
     */
    const isPreorder = Boolean(
        product.preorder ?? product.is_preorder ?? false,
    );
    const canBuy = inStock || isPreorder;

    // The shop's own words for it when it set them on the product, as the
    // product page uses; StarTech's "Sold Out" otherwise.
    const soldOutLabel = product.out_of_stock_status || 'Sold Out';

    /*
     * The saving goes with the price. A sold-out card shows no price, and a
     * "Save ৳4,000" badge over it advertised money off something that could
     * not be bought. A pre-order keeps both: it can be ordered at that price.
     */
    const showSaving = Boolean(discountInfo) && canBuy;

    /*
     * The price, or "Sold Out" in its place, as StarTech's cards have it: a
     * product that cannot be bought shows no price to be bought at. Pre-order
     * keeps its price, since it can still be ordered.
     */
    const priceStack = canBuy ? (
        <div className="price-stack">
            <span className="current-price">{formatBdt(currentPrice)}</span>
            {discountInfo && (
                <span className="old-price">{formatBdt(regularPrice)}</span>
            )}
        </div>
    ) : (
        <div className="price-stack">
            <span className="current-price price-sold-out">{soldOutLabel}</span>
        </div>
    );

    // The rail icon adds to the cart; the button buys.
    const cartActionLabel = isPreorder
        ? 'Pre-order'
        : !inStock
          ? soldOutLabel
          : hasOptions
            ? 'Choose options'
            : 'Add to cart';

    const buyActionLabel = isPreorder
        ? 'Pre-order'
        : !inStock
          ? soldOutLabel
          : hasOptions
            ? 'Choose options'
            : 'Buy Now';

    /*
     * The card's one action. Sold out, it was a disabled red "Sold Out" under
     * a red "Sold Out" in the price slot — the same fact twice and nothing to
     * do. The price slot says it now, and the button offers the next step:
     * the product page's back-in-stock form, which takes an email address or
     * a mobile number, so it is offered to everyone.
     */
    const footerAction = canBuy ? (
        <button
            type="button"
            onClick={handleBuyNow}
            className={`btn-add-cart${isPreorder ? ' is-preorder' : ''}`}
            disabled={buying}
            title={buyActionLabel}
        >
            <ShoppingCart size={16} />
            <span>{isPreorder ? 'Pre-order' : 'Buy Now'}</span>
        </button>
    ) : (
        <Link
            href={`${ROUTES.PRODUCT_DETAIL(product.slug)}#notify`}
            className="btn-add-cart is-notify"
            title="Tell me when it is back"
        >
            <Bell size={16} />
            <span>Notify me</span>
        </Link>
    );

    if (variant === 'flash') {
        return (
            <>
                <div className="flash-product-card">
                    {/* Discount Badge */}
                    {/*
                        The money off, not the percentage off.
                        
                        A percentage has to be worked against a price the badge
                        does not show — "-15%" on a ৳8,000 board and on a
                        ৳285,000 laptop look identical and are two hundred
                        pounds apart. The trade here quotes the saving, and so
                        does the shop this one is modelled on.
                    */}
                    {showSaving && (
                        <span className="card-badge discount-badge">
                            <Flame size={12} /> Save {discountInfo.saving}
                        </span>
                    )}

                    {/* Wishlist & Compare Quick Floating Actions */}
                    <div className="card-floating-actions">
                        <button
                            type="button"
                            onClick={handleAddToCart}
                            className="card-action-btn card-action-cart"
                            disabled={!canBuy}
                            title={cartActionLabel}
                            aria-label={cartActionLabel}
                        >
                            <ShoppingCart size={14} />
                        </button>
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
                            <Scale size={14} />
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
                        {/* Was 'Hardware' when none was recorded. The row
                            keeps the line either way — see the note below. */}
                        <div className="product-brand-tag">
                            {product.brand?.name || product.brand || ''}
                        </div>
                        <h4 className="product-title">
                            <Link href={ROUTES.PRODUCT_DETAIL(product.slug)}>
                                {product.name}
                            </Link>
                        </h4>

                        {/* Key Specifications */}
                        {product.specs && product.specs.length > 0 && (
                            <ul className="product-specs-list">
                                {product.specs.slice(0, 4).map((spec, idx) => (
                                    <li key={idx}>{spec}</li>
                                ))}
                            </ul>
                        )}

                        {/*
                         * No rating row and no stock bar, as on StarTech: its
                         * cards carry the name, the key features, the price
                         * and the button, and say stock through the button —
                         * "Buy Now" or "Sold Out". The rating row read "No
                         * reviews yet" on nearly every card, and the bar
                         * "Out of stock · 0 Sold" above a button that already
                         * said Sold out.
                         */}

                        {/* Price Stack & Buy Action */}
                        <div className="flash-card-footer">
                            {priceStack}
                            {footerAction}
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
                {/* The money off, as on the flash card above. */}
                {showSaving && (
                    <span className="card-badge discount-badge">
                        Save {discountInfo.saving}
                    </span>
                )}

                {/* Quick Floating Action Tools */}
                <div className="card-floating-actions">
                    <button
                        type="button"
                        onClick={handleAddToCart}
                        className="card-action-btn card-action-cart"
                        disabled={!canBuy}
                        title={cartActionLabel}
                        aria-label={cartActionLabel}
                    >
                        <ShoppingCart size={14} />
                    </button>
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
                        <Scale size={14} />
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
                    {/*
                     * Empty when there is no brand, not filled in.
                     *
                     * This said 'Authorized Brand' when the field was empty — a
                     * claim invented to fill a gap, and made precisely about
                     * the products the shop knows least about. It says nothing
                     * now, but the element stays: the line is reserved in CSS,
                     * so one unbranded product in a row does not pull its title
                     * up out of line with the three beside it.
                     */}
                    <div className="product-brand-tag">
                        {product.brand?.name || product.brand || ''}
                    </div>
                    <h4 className="product-title">
                        <Link href={ROUTES.PRODUCT_DETAIL(product.slug)}>
                            {product.name}
                        </Link>
                    </h4>

                    {/* Specs */}
                    {product.specs && product.specs.length > 0 && (
                        <ul className="product-specs-list">
                            {product.specs.slice(0, 4).map((spec, idx) => (
                                <li key={idx}>{spec}</li>
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
                        {priceStack}

                        {/*
                         * Says what it does. It was labelled "Buy" while
                         * adding to the cart, which reads as going to
                         * checkout — and an out-of-stock card should not
                         * offer the action at all.
                         */}
                        {footerAction}
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
