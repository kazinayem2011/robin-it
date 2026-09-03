import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import Modal from './Modal';
import Button from './Button';
import ProductImage from './ProductImage';
import { cartService } from '../services';
import useAppStore from '../store/useAppStore';
import { toast } from './Toast';
import { formatBdt } from '../utils/formatters';
import { boundsFor } from '../utils/cartBounds';
import { ROUTES } from '../constants/endpoints';
import { ShoppingCart, Plus, Minus } from 'lucide-react';

export default function QuickViewModal({ show, onClose, product }) {
    // Hooks first, then the early return — see ProductCard for why. This modal
    // is mounted with product=null until a card is clicked, which is exactly
    // the sequence that breaks.
    const [quantity, setQuantity] = useState(1);
    const [adding, setAdding] = useState(false);

    if (!product) return null;

    const price = product.discount_price || product.price;
    const hasDiscount =
        product.discount_price && product.discount_price < product.price;

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
                        <span className="quick-view-brand">
                            {product.brand?.name || 'Authorized Hardware'}
                        </span>
                        <h3 className="quick-view-title">{product.name}</h3>

                        {/* Price */}
                        <div className="quick-view-price-stack">
                            <span className="quick-view-current-price">
                                {formatBdt(price)}
                            </span>
                            {hasDiscount && (
                                <span className="quick-view-old-price">
                                    {formatBdt(product.price)}
                                </span>
                            )}
                        </div>

                        {/* Description / Highlights */}
                        <p className="quick-view-desc">
                            {product.short_description ||
                                product.description ||
                                'Experience next-generation performance with full manufacturer warranty and authentic quality guarantee.'}
                        </p>
                    </div>

                    {/* Footer Quantity & CTA */}
                    <div>
                        {!hasOptions && (
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
                            <Button
                                variant="primary"
                                size="md"
                                fullWidth
                                icon={ShoppingCart}
                                loading={adding}
                                disabled={!canBuy}
                                onClick={handleAddToCart}
                            >
                                {hasOptions
                                    ? 'Choose options'
                                    : !canBuy
                                      ? 'Out of stock'
                                      : isPreorder
                                        ? 'Pre-order'
                                        : 'Add to Cart'}
                            </Button>
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
