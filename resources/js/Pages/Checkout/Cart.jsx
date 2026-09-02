import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import { cartService } from '../../services';

import ProductImage from '../../Components/ProductImage';
import ProductSuggestions from '../../Components/ProductSuggestions';
import { LineItemsSkeleton } from '../../Components/Skeleton';
import { toast } from '../../Components/Toast';
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
    /* Worked out from what is in the cart. Fetched once on load rather than
       with every quantity change — the row need not twitch while somebody
       adjusts a number. */
    const [suggestions, setSuggestions] = useState([]);

    /*
     * @param withSkeleton Only the first load has nothing to show. Every later
     *   fetch is a correction to a cart already on screen, and raising the
     *   skeleton for it replaced the whole page — items, totals and all —
     *   which read as a full page load every time somebody pressed "+".
     */
    const fetchCart = async (withSkeleton = false) => {
        if (withSkeleton) setLoading(true);
        try {
            const data = await cartService.getCart();
            setCart(data);
        } catch (error) {
            console.error('Failed to load cart', error);
            setNotice(error?.message || 'We could not load your cart.');
        } finally {
            if (withSkeleton) setLoading(false);
        }
    };

    useEffect(() => {
        cartService
            .getSuggestions()
            .then(setSuggestions)
            .catch(() => {
                /* A suggestion nobody asked for is not worth an error over
                   the cart somebody is trying to check out. */
            });
    }, []);

    useEffect(() => {
        fetchCart(true);
    }, []);

    /*
     * What this line is allowed to hold, by the same rules the server applies
     * in CartService::updateItemQuantity(): never more than is in stock, never
     * more than the per-item cap, never below the product's minimum order.
     *
     * Worked out here so the buttons stop at the limit. They did not before —
     * only "a request is in flight" disabled them — so "+" past the last unit
     * in stock was a request the server was always going to refuse, and with
     * the quantity now updating on the spot the refusal showed as the number
     * going up and snapping back.
     */
    const boundsFor = (item) => {
        const stock =
            item.variant?.stock_quantity ?? item.product?.stock_quantity ?? null;
        const cap = cart?.max_quantity_per_item ?? 20;

        return {
            min: Math.max(1, Number(item.product?.min_order_quantity) || 1),
            max: stock === null ? cap : Math.min(Number(stock), cap),
        };
    };

    const updateQuantity = async (itemId, currentQty, delta) => {
        const item = cart?.items?.find((i) => i.id === itemId);
        const { min, max } = item
            ? boundsFor(item)
            : { min: 1, max: Number.POSITIVE_INFINITY };

        const newQty = currentQty + delta;

        // Nothing to ask for: the button is disabled at both ends, and this is
        // the keyboard and double-click case.
        if (newQty < min || newQty > max) return;

        setBusyItemId(itemId);
        setNotice(null);

        /*
         * Show the new number at once, then confirm it.
         *
         * A quantity is the one thing on this page the customer has just
         * decided, so waiting on a round trip to redraw it is the change
         * feeling slow rather than being slow. The line total follows from the
         * quantity and moves with it; the cart totals are the server's to
         * decide — coupons and delivery depend on them — so those settle a
         * moment later, when the fetch below lands.
         */
        setCart((prev) =>
            prev
                ? {
                      ...prev,
                      items: prev.items.map((item) =>
                          item.id === itemId
                              ? { ...item, quantity: newQty }
                              : item,
                      ),
                  }
                : prev,
        );

        try {
            await cartService.updateItemQuantity(itemId, newQty);
            await fetchCart();
            useAppStore.getState().fetchCartCount();
        } catch (error) {
            // The server knows exactly how many units are left — say so, and
            // put the row back to whatever it actually holds rather than
            // leaving the guess on screen.
            setNotice(error?.message || 'We could not update that quantity.');
            await fetchCart();
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
            <>
                <div className="checkout-container container">
                    <LineItemsSkeleton count={3} />
                </div>
            </>
        );
    }

    if (!cart || !cart.items || cart.items.length === 0) {
        return (
            <>
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

                {/* An empty cart was a dead end: a line of apology and a link
                    away from the page. Somewhere to go beats being told to go
                    somewhere. */}
                <div className="checkout-container container">
                    <ProductSuggestions
                        products={suggestions}
                        title="Popular right now"
                    />
                </div>
            </>
        );
    }

    const subtotal = cart.totals?.subtotal ?? 0;
    const issues = cart.issues || [];
    const hasBlockingIssue = issues.length > 0;

    return (
        <>
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
                                                        item.quantity <=
                                                            boundsFor(item).min
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
                                                        busyItemId ===
                                                            item.id ||
                                                        item.quantity >=
                                                            boundsFor(item).max
                                                    }
                                                    title={
                                                        item.quantity >=
                                                        boundsFor(item).max
                                                            ? `Only ${boundsFor(item).max} available`
                                                            : undefined
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

                <ProductSuggestions
                    products={suggestions}
                    title="You may also like"
                    className="cart-suggestions"
                />
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
Cart.layout = mainLayout;
