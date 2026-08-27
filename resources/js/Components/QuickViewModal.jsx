import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import Modal from './Modal';
import Button from './Button';
import ProductImage from './ProductImage';
import { cartService } from '../services';
import useAppStore from '../store/useAppStore';
import { toast } from './Toast';
import { formatBdt } from '../utils/formatters';
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

    const handleAddToCart = async () => {
        setAdding(true);
        try {
            await cartService.addToCart(product.id, quantity);
            useAppStore.getState().fetchCartCount();
            toast.success(
                `Added ${quantity}x "${product.name}" to cart!`,
                'Cart Updated',
            );
            onClose();
        } catch (error) {
            console.error('Failed to add to cart', error);
            toast.error('Failed to add product to cart.', 'Error');
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
                        <div className="quick-view-qty-row">
                            <span className="quick-view-qty-label">
                                Quantity:
                            </span>
                            <div className="quick-view-qty-control">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setQuantity((q) => Math.max(1, q - 1))
                                    }
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
                                    onClick={() => setQuantity((q) => q + 1)}
                                    className="quick-view-qty-btn"
                                    aria-label="Increase quantity"
                                >
                                    <Plus size={14} />
                                </button>
                            </div>
                        </div>

                        <div className="quick-view-cta-row">
                            <Button
                                variant="primary"
                                size="md"
                                fullWidth
                                icon={ShoppingCart}
                                loading={adding}
                                onClick={handleAddToCart}
                            >
                                Add to Cart
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
