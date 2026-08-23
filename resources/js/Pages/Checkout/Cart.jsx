import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import { cartService } from '../../services';
import { Button, Spinner, Card, ProductImage, toast } from '../../Components';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { ShoppingCart, Trash2, ArrowRight, AlertTriangle } from 'lucide-react';
import useAppStore from '../../store/useAppStore';
import './Checkout.css';

export default function Cart() {
    const [cart, setCart] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busyItemId, setBusyItemId] = useState(null);
    const [notice, setNotice] = useState(null);

    const fetchCart = async () => {
        setLoading(true);
        try {
            const data = await cartService.getCart();
            setCart(data);
        } catch (error) {
            console.error('Failed to load cart', error);
            setNotice(error?.message || 'We could not load your cart.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchCart();
    }, []);

    const updateQuantity = async (itemId, currentQty, delta) => {
        const newQty = currentQty + delta;
        if (newQty < 1) return;

        setBusyItemId(itemId);
        setNotice(null);
        try {
            await cartService.updateItemQuantity(itemId, newQty);
            await fetchCart();
            useAppStore.getState().fetchCartCount();
        } catch (error) {
            // The server knows exactly how many units are left — say so.
            setNotice(error?.message || 'We could not update that quantity.');
        } finally {
            setBusyItemId(null);
        }
    };

    const removeItem = async (itemId) => {
        setBusyItemId(itemId);
        try {
            await cartService.removeItem(itemId);
            await fetchCart();
            useAppStore.getState().fetchCartCount();
            toast.success('Item removed from your cart.', 'Cart Updated');
        } catch (error) {
            setNotice(error?.message || 'We could not remove that item.');
        } finally {
            setBusyItemId(null);
        }
    };

    if (loading) {
        return (
            <MainLayout>
                <Spinner text="Loading shopping cart..." fullHeight />
            </MainLayout>
        );
    }

    if (!cart || !cart.items || cart.items.length === 0) {
        return (
            <MainLayout>
                <Head title={`Shopping Cart — ${siteConfig.name}`} />
                <div className="checkout-container checkout-empty-box container">
                    <ShoppingCart size={48} className="checkout-empty-icon" />
                    <h2 className="checkout-empty-title">
                        Your Shopping Cart is Empty
                    </h2>
                    <p className="checkout-empty-desc">
                        Looks like you haven't added any products to your cart
                        yet.
                    </p>
                    <Link href={ROUTES.SHOP} className="btn btn-primary">
                        Continue Shopping
                    </Link>
                </div>
            </MainLayout>
        );
    }

    const subtotal = cart.totals?.subtotal ?? 0;
    const issues = cart.issues || [];
    const hasBlockingIssue = issues.length > 0;

    return (
        <MainLayout>
            <Head title={`Shopping Cart — ${siteConfig.name}`} />

            <div className="checkout-container container">
                <h1 className="checkout-title">
                    Shopping Cart ({cart.items.length} Items)
                </h1>

                {(notice || hasBlockingIssue) && (
                    <div className="cart-alert-banner" role="alert">
                        <AlertTriangle size={18} />
                        <div>
                            {notice && <p>{notice}</p>}
                            {issues.map((issue) => (
                                <p key={issue.item_id}>
                                    {issue.reason === 'unavailable'
                                        ? `"${issue.product_name}" is no longer available — please remove it to continue.`
                                        : `Only ${issue.available} left of "${issue.product_name}" (you have ${issue.requested}). Please reduce the quantity.`}
                                </p>
                            ))}
                        </div>
                    </div>
                )}

                <div className="checkout-grid">
                    {/* Left Column: Cart Items List */}
                    <div className="cart-items-section">
                        <div className="cart-items-card">
                            <div className="cart-table-header">
                                <span>Product</span>
                                <span>Quantity & Subtotal</span>
                            </div>

                            <div className="cart-table-body">
                                {cart.items.map((item) => {
                                    // Price belongs to the option when there
                                    // is one — the parent's price is not what
                                    // this line costs.
                                    const unitPrice =
                                        item.variant?.effective_price ??
                                        item.product.effective_price ??
                                        item.product.price;
                                    const itemSubtotal =
                                        unitPrice * item.quantity;

                                    return (
                                        <div
                                            key={item.id}
                                            className="cart-item-row"
                                        >
                                            <ProductImage
                                                product={item.product}
                                                alt={item.product.name}
                                                className="cart-item-img"
                                            />
                                            <div className="cart-item-info">
                                                <Link
                                                    href={ROUTES.PRODUCT_DETAIL(
                                                        item.product.slug,
                                                    )}
                                                    className="cart-item-name"
                                                >
                                                    {item.product.name}
                                                </Link>
                                                {item.variant && (
                                                    <div className="cart-item-variant">
                                                        {item.variant.name}
                                                    </div>
                                                )}
                                                <div className="cart-item-price">
                                                    {formatBdt(unitPrice)}
                                                </div>
                                            </div>

                                            <div className="cart-qty-control">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        updateQuantity(
                                                            item.id,
                                                            item.quantity,
                                                            -1,
                                                        )
                                                    }
                                                    className="cart-qty-btn"
                                                    disabled={
                                                        busyItemId ===
                                                            item.id ||
                                                        item.quantity <= 1
                                                    }
                                                >
                                                    -
                                                </button>
                                                <span className="cart-qty-val">
                                                    {item.quantity}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        updateQuantity(
                                                            item.id,
                                                            item.quantity,
                                                            1,
                                                        )
                                                    }
                                                    className="cart-qty-btn"
                                                    disabled={
                                                        busyItemId === item.id
                                                    }
                                                >
                                                    +
                                                </button>
                                            </div>

                                            <div className="cart-item-total">
                                                {formatBdt(itemSubtotal)}
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeItem(item.id)
                                                }
                                                className="cart-item-remove-btn"
                                                title="Remove item"
                                                disabled={
                                                    busyItemId === item.id
                                                }
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    {/* Right Column: Cart Summary */}
                    <div className="checkout-summary-section">
                        <div className="summary-card">
                            <h3 className="summary-card-title">
                                Order Summary
                            </h3>

                            <div className="summary-totals">
                                <div className="summary-line">
                                    <span>Subtotal</span>
                                    <strong className="summary-line-val">
                                        {formatBdt(subtotal)}
                                    </strong>
                                </div>
                                <div className="summary-line">
                                    <span>Estimated Delivery</span>
                                    <span className="summary-line-val">
                                        Calculated at Checkout
                                    </span>
                                </div>
                                <div className="summary-grand-total">
                                    <span>Total (Excl. Shipping)</span>
                                    <span>{formatBdt(subtotal)}</span>
                                </div>
                            </div>

                            {hasBlockingIssue ? (
                                <button
                                    type="button"
                                    className="btn btn-primary btn-block"
                                    disabled
                                    title="Resolve the highlighted items to continue"
                                >
                                    <span>RESOLVE ITEMS TO CONTINUE</span>
                                </button>
                            ) : (
                                <Link
                                    href={ROUTES.CHECKOUT}
                                    className="btn btn-primary btn-block hover-lift"
                                >
                                    <span>PROCEED TO CHECKOUT</span>
                                    <ArrowRight size={16} />
                                </Link>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </MainLayout>
    );
}
