import { useState, useEffect } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import { useFormik } from 'formik';
import MainLayout from '../../Layouts/MainLayout';
import { cartService, checkoutService, couponService } from '../../services';
import {
    Button,
    LineItemsSkeleton,
    ProductImage,
    toast,
} from '../../Components';
import useAppStore from '../../store/useAppStore';
import { checkoutSchema } from '../../validations';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { ShoppingCart, Tag, X, AlertTriangle } from 'lucide-react';
import './Checkout.css';

export default function Checkout() {
    const [cart, setCart] = useState(null);
    const [loading, setLoading] = useState(true);

    const [couponInput, setCouponInput] = useState('');
    const [appliedCoupon, setAppliedCoupon] = useState(null);
    const [applyingCoupon, setApplyingCoupon] = useState(false);
    const [checkoutBlocker, setCheckoutBlocker] = useState(null);

    const formik = useFormik({
        initialValues: {
            name: '',
            phone: '',
            city: '',
            zone: '',
            street_address: '',
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
            <MainLayout>
                <div className="checkout-container container">
                    <LineItemsSkeleton count={3} />
                </div>
            </MainLayout>
        );
    }

    if (!cart || !cart.items || cart.items.length === 0) {
        return (
            <MainLayout>
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
            </MainLayout>
        );
    }

    // Server-calculated so the figure shown here is exactly what will be charged.
    const serverTotals = appliedCoupon?.totals || cart.totals || {};
    const subtotal = serverTotals.subtotal ?? 0;
    const shipping = serverTotals.shipping_fee ?? 0;
    const discount = appliedCoupon ? (serverTotals.discount ?? 0) : 0;
    const total =
        serverTotals.total ?? Math.max(0, subtotal + shipping - discount);

    return (
        <MainLayout>
            <Head title={`Checkout — ${siteConfig.name}`} />

            <div className="checkout-container container">
                <h1 className="checkout-title">Checkout</h1>

                <div className="checkout-grid">
                    <div className="checkout-form-section">
                        <div className="checkout-form-card">
                            <h3 className="checkout-section-header">
                                1. Delivery Information
                            </h3>
                            <form
                                id="checkout-form"
                                onSubmit={formik.handleSubmit}
                            >
                                <div className="form-group">
                                    <label className="form-control-label">
                                        Full Name{' '}
                                        <span className="required-asterisk">*</span>
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
                                        <span className="required-asterisk">*</span>
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

                                <div className="checkout-form-grid">
                                    <div className="form-group">
                                        <label className="form-control-label">
                                            City / District{' '}
                                            <span className="required-asterisk">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            name="city"
                                            className={`form-control-input ${formik.touched.city && formik.errors.city ? 'has-error' : ''}`}
                                            placeholder="e.g. Dhaka"
                                            onChange={formik.handleChange}
                                            onBlur={formik.handleBlur}
                                            value={formik.values.city}
                                        />
                                        {formik.touched.city &&
                                            formik.errors.city && (
                                                <span className="form-control-error">
                                                    {formik.errors.city}
                                                </span>
                                            )}
                                    </div>
                                    <div className="form-group">
                                        <label className="form-control-label">
                                            Zone / Thana
                                        </label>
                                        <input
                                            type="text"
                                            name="zone"
                                            className="form-control-input"
                                            placeholder="e.g. Dhanmondi"
                                            onChange={formik.handleChange}
                                            onBlur={formik.handleBlur}
                                            value={formik.values.zone}
                                        />
                                    </div>
                                </div>

                                <div className="form-group">
                                    <label className="form-control-label">
                                        Full Street Address{' '}
                                        <span className="required-asterisk">*</span>
                                    </label>
                                    <textarea
                                        name="street_address"
                                        className={`form-control-input ${formik.touched.street_address && formik.errors.street_address ? 'has-error' : ''}`}
                                        placeholder="House number, Road number, Area"
                                        rows="3"
                                        onChange={formik.handleChange}
                                        onBlur={formik.handleBlur}
                                        value={formik.values.street_address}
                                    ></textarea>
                                    {formik.touched.street_address &&
                                        formik.errors.street_address && (
                                            <span className="form-control-error">
                                                {formik.errors.street_address}
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
                                                <span className="item-qty">
                                                    Qty: {item.quantity}
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
                                    <span className="summary-line-val">
                                        {formatBdt(shipping)}
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
        </MainLayout>
    );
}
