import { useState, useEffect } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import { useFormik } from 'formik';
import { mainLayout } from '../../Layouts/MainLayout';
import { cartService, checkoutService, couponService } from '../../services';
import Button from '../../Components/Button';
import ProductImage from '../../Components/ProductImage';
import { LineItemsSkeleton } from '../../Components/Skeleton';
import { toast } from '../../Components/Toast';
import useAppStore from '../../store/useAppStore';
import { checkoutSchema } from '../../validations';
import { formatBdt } from '../../utils/formatters';
import { boundsFor as cartBounds } from '../../utils/cartBounds';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { ShoppingCart, Tag, X, AlertTriangle } from 'lucide-react';
import './Checkout.css';

/**
 * What the two zones are called on screen. The values are the shop's, matching
 * ShippingRates::ZONES — the labels are for the customer.
 */
const DELIVERY_ZONES = [
    { value: 'inside_dhaka', label: 'Inside Dhaka' },
    { value: 'outside_dhaka', label: 'Outside Dhaka' },
];

/**
 * One line for a saved address, for the picker.
 *
 * The street first, because that is what tells two of the customer's own
 * addresses apart; the zone after it, because that is what decides delivery.
 * Addresses saved before the zone was asked for fall back to the area and
 * city they were kept with, so an older one still says where it is.
 */
export const addressLabel = (addr) => {
    const where =
        DELIVERY_ZONES.find((z) => z.value === addr.delivery_zone)?.label ||
        [addr.zone, addr.city].filter(Boolean).join(', ');

    return (
        [addr.street_address, where].filter(Boolean).join(' — ') +
        (addr.is_default ? '  (default)' : '')
    );
};

/**
 * @param addresses Where this customer has had orders delivered before. Empty
 *                  for a guest, who has nowhere to keep them.
 * @param contact   Their account's name and number, so even a first order does
 *                  not ask for what they registered with.
 * @param deliveryRates What each zone costs and the spend above which delivery
 *                  is free, so the choice can be priced as it is made rather
 *                  than after the server has been asked.
 */
export default function Checkout({
    addresses = [],
    contact = null,
    deliveryRates = null,
}) {
    const [cart, setCart] = useState(null);
    const [loading, setLoading] = useState(true);

    // The default address if one is marked, else the most recent — the list
    // arrives in that order. `null` means "typing a new one".
    const [chosenAddressId, setChosenAddressId] = useState(
        addresses.length ? addresses[0].id : null,
    );

    const [couponInput, setCouponInput] = useState('');
    const [appliedCoupon, setAppliedCoupon] = useState(null);
    const [applyingCoupon, setApplyingCoupon] = useState(false);
    const [checkoutBlocker, setCheckoutBlocker] = useState(null);
    const [busyItemId, setBusyItemId] = useState(null);

    /* The same rules the cart page and the server apply. */
    const boundsFor = (item) => cartBounds(item, cart);

    /*
     * Change a quantity without going back to the cart.
     *
     * The summary said "Qty: 2" and offered nothing, so spotting the wrong
     * number at the last step meant leaving checkout, fixing it, and finding
     * the way forward again — with the delivery details typed in the meantime
     * left behind.
     *
     * The new number shows at once and the server settles the totals, which is
     * how the cart page behaves; the two lists are the same list.
     */
    const changeQuantity = async (item, delta) => {
        const { min, max } = boundsFor(item);
        const next = item.quantity + delta;

        if (next < min || next > max) return;

        setBusyItemId(item.id);

        setCart((prev) =>
            prev
                ? {
                      ...prev,
                      items: prev.items.map((line) =>
                          line.id === item.id
                              ? { ...line, quantity: next }
                              : line,
                      ),
                  }
                : prev,
        );

        try {
            await cartService.updateItemQuantity(item.id, next);
            setCart(await cartService.getCart());
            useAppStore.getState().fetchCartCount();
        } catch (error) {
            toast.error(error?.message || 'We could not update that quantity.');
            // Put the line back to whatever the cart actually holds.
            setCart(await cartService.getCart());
        } finally {
            setBusyItemId(null);
        }
    };

    const formik = useFormik({
        initialValues: {
            name: addresses[0]?.name || contact?.name || '',
            phone: addresses[0]?.phone || contact?.phone || '',
            address: addresses[0]?.street_address || '',
            // A saved address remembers which zone it is in, so picking it does
            // not ask the customer to say so again.
            delivery_zone: addresses[0]?.delivery_zone || '',
            payment: 'cod',
        },
        validationSchema: checkoutSchema,
        onSubmit: async (values, { setSubmitting }) => {
            try {
                const payload = {
                    ...values,
                    // The discount itself is recalculated server-side; only the
                    // code travels, so a tampered amount can't reach the order.
                    coupon_code: appliedCoupon ? appliedCoupon.code : null,
                };
                const data = await checkoutService.processCheckout(payload);
                if (data && data.order_number) {
                    useAppStore.getState().fetchCartCount();
                    toast.success(
                        'Order placed successfully!',
                        'Checkout Complete',
                    );
                    router.visit(ROUTES.ORDER_SUCCESS(data.order_number));
                }
            } catch (error) {
                console.error('Checkout failed', error);

                // Show what actually went wrong — out of stock, expired promo,
                // an invalid phone number — instead of one catch-all sentence.
                toast.error(
                    error?.message ||
                        'We could not place your order. Please try again.',
                    'Order Error',
                );

                if (
                    error?.code === 'OUT_OF_STOCK' ||
                    error?.code === 'PRODUCT_UNAVAILABLE'
                ) {
                    setCheckoutBlocker(error.message);
                }

                if (error?.code === 'COUPON_INVALID') {
                    setAppliedCoupon(null);
                }
            } finally {
                setSubmitting(false);
            }
        },
    });

    const applyAddress = (addr) => {
        setChosenAddressId(addr.id);
        formik.setValues({
            ...formik.values,
            name: addr.name || contact?.name || '',
            phone: addr.phone || contact?.phone || '',
            address: addr.street_address || '',
            delivery_zone: addr.delivery_zone || '',
        });
    };

    // Clears the address but keeps who they are — the name and number are the
    // same person wherever the parcel goes.
    const startNewAddress = () => {
        setChosenAddressId(null);
        formik.setValues({
            ...formik.values,
            address: '',
            delivery_zone: '',
        });
    };

    useEffect(() => {
        const fetchCart = async () => {
            try {
                const data = await cartService.getCart();
                setCart(data);
            } catch (error) {
                console.error('Failed to load cart', error);
            } finally {
                setLoading(false);
            }
        };
        fetchCart();
    }, []);

    const handleApplyCoupon = async (e) => {
        e.preventDefault();
        if (!couponInput.trim()) return;

        setApplyingCoupon(true);
        try {
            // The server prices the discount against the real cart and returns
            // refreshed totals; we display those rather than recomputing here.
            const data = await couponService.applyCoupon(couponInput.trim());
            setAppliedCoupon(data);
            toast.success(
                `Promo code ${data.code} applied — you save ${formatBdt(data.discount)}.`,
                'Discount Applied',
            );
        } catch (err) {
            setAppliedCoupon(null);
            toast.error(
                err?.message || 'That promo code is not valid.',
                'Promo Code',
            );
        } finally {
            setApplyingCoupon(false);
        }
    };

    const handleRemoveCoupon = () => {
        setAppliedCoupon(null);
        setCouponInput('');
        toast.info('Coupon removed.');
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
                <Head title={`Checkout — ${siteConfig.name}`} />
                <div className="checkout-container checkout-empty-box container">
                    <ShoppingCart size={48} className="checkout-empty-icon" />
                    <h2 className="checkout-empty-title">Your cart is empty</h2>
                    <p className="checkout-empty-desc">
                        Add some products to your cart before proceeding to
                        checkout.
                    </p>
                    <Link href={ROUTES.SHOP} className="btn btn-primary">
                        Browse Hardware
                    </Link>
                </div>
            </>
        );
    }

    // Server-calculated so the figure shown here is exactly what will be charged.
    const serverTotals = appliedCoupon?.totals || cart.totals || {};
    const subtotal = serverTotals.subtotal ?? 0;
    const discount = appliedCoupon ? (serverTotals.discount ?? 0) : 0;

    const zoneRates = deliveryRates?.zones || {};
    const freeOver = deliveryRates?.free_over ?? null;

    /* Judged on the goods before the coupon, the same as the server does — a
       promo code should not cost the customer their free delivery. */
    const freeDelivery = freeOver !== null && subtotal >= freeOver;

    /*
     * Priced from the zone on screen rather than from the cart's totals.
     *
     * The cart was costed before there was an address, so it carries the
     * inside-Dhaka rate; showing that after somebody has chosen "Outside Dhaka"
     * would understate the bill right up to the moment they pay. The server
     * still decides what is charged — this only has to agree with it.
     */
    const quotedShipping = serverTotals.shipping_fee ?? 0;

    const shipping = freeDelivery
        ? 0
        : formik.values.delivery_zone
          ? (zoneRates[formik.values.delivery_zone] ?? quotedShipping)
          : quotedShipping;

    /*
     * The server's total, moved by the difference in delivery — not rebuilt
     * from the lines. VAT is worked out server-side and can be inclusive or
     * added on top, so recomputing here would quietly drop it from the bill.
     */
    const total = Math.max(
        0,
        (serverTotals.total ?? subtotal + quotedShipping - discount) -
            quotedShipping +
            shipping,
    );

    return (
        <>
            <Head title={`Checkout — ${siteConfig.name}`} />

            <div className="checkout-container container">
                <h1 className="checkout-title">Checkout</h1>

                <div className="checkout-grid">
                    <div className="checkout-form-section">
                        <div className="checkout-form-card">
                            <h3 className="checkout-section-header">
                                1. Delivery Information
                            </h3>

                            {/*
                             * A list rather than a stack of cards.
                             *
                             * Each saved address was a card two lines tall, so
                             * four of them pushed the form itself off the
                             * screen — on a phone the customer scrolled past
                             * their own addresses to reach the fields. A
                             * select says the same thing in one row, and shows
                             * whichever is chosen without hiding the rest.
                             *
                             * Shown from the first saved address, not the
                             * second: with one saved, the only way to deliver
                             * somewhere else was to overwrite the boxes and
                             * hope, which is not an offer the page was making.
                             */}
                            {addresses.length > 0 && (
                                <div className="form-group">
                                    <label
                                        className="form-control-label"
                                        htmlFor="saved-address"
                                    >
                                        Deliver to
                                    </label>
                                    <select
                                        id="saved-address"
                                        className="form-control-input"
                                        value={chosenAddressId ?? 'new'}
                                        onChange={(e) => {
                                            const value = e.target.value;

                                            if (value === 'new') {
                                                startNewAddress();
                                                return;
                                            }

                                            const addr = addresses.find(
                                                (a) => String(a.id) === value,
                                            );

                                            if (addr) applyAddress(addr);
                                        }}
                                    >
                                        {addresses.map((addr) => (
                                            <option
                                                key={addr.id}
                                                value={addr.id}
                                            >
                                                {addressLabel(addr)}
                                            </option>
                                        ))}
                                        <option value="new">
                                            + Deliver somewhere else
                                        </option>
                                    </select>
                                </div>
                            )}

                            <form
                                id="checkout-form"
                                onSubmit={formik.handleSubmit}
                                noValidate
                            >
                                <div className="form-group">
                                    <label className="form-control-label">
                                        Full Name{' '}
                                        <span className="required-asterisk">
                                            *
                                        </span>
                                    </label>
                                    <input
                                        type="text"
                                        name="name"
                                        className={`form-control-input ${formik.touched.name && formik.errors.name ? 'has-error' : ''}`}
                                        placeholder="e.g. Rahim Chowdhury"
                                        onChange={formik.handleChange}
                                        onBlur={formik.handleBlur}
                                        value={formik.values.name}
                                    />
                                    {formik.touched.name &&
                                        formik.errors.name && (
                                            <span className="form-control-error">
                                                {formik.errors.name}
                                            </span>
                                        )}
                                </div>

                                <div className="form-group">
                                    <label className="form-control-label">
                                        Bangladeshi Mobile Number{' '}
                                        <span className="required-asterisk">
                                            *
                                        </span>
                                    </label>
                                    <input
                                        type="text"
                                        name="phone"
                                        className={`form-control-input ${formik.touched.phone && formik.errors.phone ? 'has-error' : ''}`}
                                        placeholder="01711223344"
                                        onChange={formik.handleChange}
                                        onBlur={formik.handleBlur}
                                        value={formik.values.phone}
                                    />
                                    {formik.touched.phone &&
                                        formik.errors.phone && (
                                            <span className="form-control-error">
                                                {formik.errors.phone}
                                            </span>
                                        )}
                                </div>

                                <div className="form-group">
                                    <label className="form-control-label">
                                        Delivery Address{' '}
                                        <span className="required-asterisk">
                                            *
                                        </span>
                                    </label>
                                    <textarea
                                        name="address"
                                        className={`form-control-input ${formik.touched.address && formik.errors.address ? 'has-error' : ''}`}
                                        placeholder="House 12, Road 5, Dhanmondi, Dhaka-1205"
                                        rows="3"
                                        onChange={formik.handleChange}
                                        onBlur={formik.handleBlur}
                                        value={formik.values.address}
                                    ></textarea>
                                    {formik.touched.address &&
                                        formik.errors.address && (
                                            <span className="form-control-error">
                                                {formik.errors.address}
                                            </span>
                                        )}
                                </div>

                                {/*
                                 * Asked rather than read off the address.
                                 * Delivery is priced by zone, and looking for
                                 * the word "Dhaka" in a line somebody wrote
                                 * charges "Dhaka Road, Feni" the local rate.
                                 * Priced here so the choice and its cost are
                                 * the same decision.
                                 */}
                                <div className="form-group">
                                    <label className="form-control-label">
                                        Delivery Area{' '}
                                        <span className="required-asterisk">
                                            *
                                        </span>
                                    </label>
                                    <div className="delivery-zone-choice">
                                        {DELIVERY_ZONES.map((zone) => (
                                            <label
                                                key={zone.value}
                                                className={`delivery-zone-option ${
                                                    formik.values
                                                        .delivery_zone ===
                                                    zone.value
                                                        ? 'is-chosen'
                                                        : ''
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="delivery_zone"
                                                    value={zone.value}
                                                    checked={
                                                        formik.values
                                                            .delivery_zone ===
                                                        zone.value
                                                    }
                                                    onChange={
                                                        formik.handleChange
                                                    }
                                                    onBlur={formik.handleBlur}
                                                />
                                                <span className="delivery-zone-name">
                                                    {zone.label}
                                                </span>
                                                <span className="delivery-zone-fee">
                                                    {freeDelivery
                                                        ? 'Free'
                                                        : formatBdt(
                                                              zoneRates[
                                                                  zone.value
                                                              ] ?? 0,
                                                          )}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                    {formik.touched.delivery_zone &&
                                        formik.errors.delivery_zone && (
                                            <span className="form-control-error">
                                                {formik.errors.delivery_zone}
                                            </span>
                                        )}
                                    {freeDelivery && (
                                        <span className="delivery-zone-note">
                                            This order qualifies for free
                                            delivery.
                                        </span>
                                    )}
                                </div>
                            </form>
                        </div>

                        <div className="checkout-form-card">
                            <h3 className="checkout-section-header">
                                2. Payment Method
                            </h3>
                            <div className="payment-method-selector">
                                <label className="payment-method-card selected">
                                    <input
                                        type="radio"
                                        name="payment"
                                        value="cod"
                                        checked={
                                            formik.values.payment === 'cod'
                                        }
                                        onChange={formik.handleChange}
                                    />
                                    <div className="payment-method-info">
                                        <strong className="payment-method-title">
                                            Cash on Delivery (COD)
                                        </strong>
                                        <p className="payment-method-desc">
                                            Pay in cash upon physical delivery
                                            across all 64 districts in
                                            Bangladesh.
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div className="checkout-summary-section">
                        <div className="summary-card">
                            <h3 className="summary-card-title">
                                Order Overview
                            </h3>

                            <div className="summary-items">
                                {cart.items.map((item) => {
                                    const unitPrice =
                                        item.variant?.effective_price ??
                                        item.product.effective_price ??
                                        item.product.price;
                                    return (
                                        <div
                                            key={item.id}
                                            className="summary-item"
                                        >
                                            <ProductImage
                                                product={item.product}
                                                alt={item.product.name}
                                                className="item-img-stub"
                                            />
                                            <div className="item-info">
                                                <span className="item-name">
                                                    {item.product.name}
                                                    {item.variant
                                                        ? ` (${item.variant.name})`
                                                        : ''}
                                                </span>

                                                {/*
                                                 * Changeable here. It used to
                                                 * read "Qty: 2" and nothing
                                                 * else, so noticing the wrong
                                                 * number at the last step meant
                                                 * going back to the cart and
                                                 * finding your way forward
                                                 * again.
                                                 */}
                                                <span className="item-qty-control">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            changeQuantity(
                                                                item,
                                                                -1,
                                                            )
                                                        }
                                                        disabled={
                                                            busyItemId ===
                                                                item.id ||
                                                            item.quantity <=
                                                                boundsFor(item)
                                                                    .min
                                                        }
                                                        aria-label={`Fewer of ${item.product.name}`}
                                                    >
                                                        −
                                                    </button>
                                                    <span>{item.quantity}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            changeQuantity(
                                                                item,
                                                                1,
                                                            )
                                                        }
                                                        disabled={
                                                            busyItemId ===
                                                                item.id ||
                                                            item.quantity >=
                                                                boundsFor(item)
                                                                    .max
                                                        }
                                                        title={
                                                            item.quantity >=
                                                            boundsFor(item).max
                                                                ? `Only ${boundsFor(item).max} available`
                                                                : undefined
                                                        }
                                                        aria-label={`More of ${item.product.name}`}
                                                    >
                                                        +
                                                    </button>
                                                </span>
                                            </div>
                                            <div className="item-price">
                                                {formatBdt(
                                                    unitPrice * item.quantity,
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="checkout-coupon-box">
                                {appliedCoupon ? (
                                    <div className="coupon-applied-badge">
                                        <div className="coupon-applied-left">
                                            <Tag size={16} />
                                            <div>
                                                <strong>
                                                    {appliedCoupon.code}
                                                </strong>
                                                <span className="coupon-desc-text">
                                                    Save{' '}
                                                    {formatBdt(
                                                        appliedCoupon.discount,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            className="coupon-remove-btn"
                                            onClick={handleRemoveCoupon}
                                            title="Remove coupon"
                                        >
                                            <X size={16} />
                                        </button>
                                    </div>
                                ) : (
                                    <form
                                        onSubmit={handleApplyCoupon}
                                        className="coupon-form"
                                        noValidate
                                    >
                                        <input
                                            type="text"
                                            placeholder="Promo Code"
                                            value={couponInput}
                                            onChange={(e) =>
                                                setCouponInput(
                                                    e.target.value.toUpperCase(),
                                                )
                                            }
                                            className="coupon-input"
                                        />
                                        <Button
                                            type="submit"
                                            variant="secondary"
                                            size="sm"
                                            loading={applyingCoupon}
                                        >
                                            Apply
                                        </Button>
                                    </form>
                                )}
                            </div>

                            {checkoutBlocker && (
                                <div className="cart-alert-banner" role="alert">
                                    <AlertTriangle size={18} />
                                    <div>
                                        <p>{checkoutBlocker}</p>
                                        <Link
                                            href={ROUTES.CART}
                                            className="cart-alert-link"
                                        >
                                            Review your cart
                                        </Link>
                                    </div>
                                </div>
                            )}

                            <div className="summary-totals">
                                <div className="summary-line">
                                    <span>Subtotal</span>
                                    <strong className="summary-line-val">
                                        {formatBdt(subtotal)}
                                    </strong>
                                </div>
                                <div className="summary-line">
                                    <span>Express Delivery</span>
                                    {/*
                                     * "৳0" reads as a number that failed to
                                     * load. Free delivery is something the
                                     * shop is giving the customer, so it says
                                     * so.
                                     */}
                                    <span
                                        className={`summary-line-val ${shipping === 0 ? 'text-success' : ''}`}
                                    >
                                        {shipping === 0
                                            ? 'Free'
                                            : formatBdt(shipping)}
                                    </span>
                                </div>
                                {appliedCoupon && (
                                    <div className="summary-line discount-line">
                                        <span>Promo Discount</span>
                                        <span className="summary-line-val text-success">
                                            - {formatBdt(discount)}
                                        </span>
                                    </div>
                                )}
                                <div className="summary-grand-total">
                                    <span>Grand Total</span>
                                    <span>{formatBdt(total)}</span>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                form="checkout-form"
                                variant="primary"
                                size="lg"
                                fullWidth
                                loading={formik.isSubmitting}
                            >
                                Confirm Order
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
Checkout.layout = mainLayout;
