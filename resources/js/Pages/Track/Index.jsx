import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { useFormik } from 'formik';
import { mainLayout } from '../../Layouts/MainLayout';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import StatusBadge from '../../Components/StatusBadge';
import { toast } from '../../Components/Toast';
import { orderTrackingService } from '../../services';
import { trackingSchema } from '../../validations';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import {
    Truck,
    Package,
    CheckCircle2,
    Clock,
    Search,
    MapPin,
    CreditCard,
    ExternalLink,
    PhoneCall,
} from 'lucide-react';
import './Track.css';

export default function TrackOrder() {
    const [trackingResult, setTrackingResult] = useState(null);

    const formik = useFormik({
        initialValues: {
            order_number: '',
            phone: '',
        },
        validationSchema: trackingSchema,
        onSubmit: async (values, { setSubmitting }) => {
            try {
                const data = await orderTrackingService.trackOrder(
                    values.order_number,
                    values.phone,
                );
                if (data) {
                    setTrackingResult(data);
                    toast.success('Live order details loaded!', 'Order Found');
                }
            } catch (error) {
                console.error('Failed to track order', error);
                setTrackingResult(null);
                toast.error(
                    'No order found matching the provided Order Number and Mobile Number.',
                    'Order Not Found',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    // The form has done its job once an order is on screen; keeping it there
    // pushes the answer down the page and invites re-typing what was just
    // asked for.
    const trackAnother = () => {
        setTrackingResult(null);
        formik.resetForm();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const STEPS = [
        { id: 1, label: 'Order Placed', icon: Clock },
        { id: 2, label: 'Packaging', icon: Package },
        { id: 3, label: 'Out for Delivery', icon: Truck },
        { id: 4, label: 'Delivered', icon: CheckCircle2 },
    ];

    const currentStep = trackingResult?.current_step || 1;
    const progressWidth = Math.max(
        0,
        Math.min(100, ((currentStep - 1) / (STEPS.length - 1)) * 100),
    );

    return (
        <>
            <Head title={`Track Your Order — ${siteConfig.name}`} />

            <div className="tracking-page-wrapper container">
                {/* Search / Track Form Hero — stands aside once it has an answer. */}
                {trackingResult ? (
                    <div className="tracking-found-bar">
                        <div className="tracking-found-text">
                            <CheckCircle2 size={18} />
                            <span>
                                Showing order{' '}
                                <strong>{trackingResult.order_number}</strong>
                            </span>
                        </div>
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={Search}
                            onClick={trackAnother}
                        >
                            Track another order
                        </Button>
                    </div>
                ) : (
                    <div className="tracking-hero-card">
                        <div className="tracking-hero-icon">
                            <Truck size={30} />
                        </div>
                        <h1 className="tracking-hero-title">
                            Live Order Tracker
                        </h1>
                        <p className="tracking-hero-desc">
                            Enter your Order Number and Bangladeshi Mobile
                            Number to track delivery progress.
                        </p>

                        <form onSubmit={formik.handleSubmit}>
                            <div className="tracking-form-grid">
                                <FormInput
                                    id="order_number"
                                    name="order_number"
                                    label="Order Number"
                                    placeholder="e.g. ORD-ABC123XYZ"
                                    value={formik.values.order_number}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.order_number &&
                                        formik.errors.order_number
                                    }
                                />

                                <FormInput
                                    id="phone"
                                    name="phone"
                                    label="Mobile Number"
                                    placeholder="e.g. 01711223344"
                                    isBdPhone={true}
                                    value={formik.values.phone}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.phone &&
                                        formik.errors.phone
                                    }
                                />
                            </div>

                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                fullWidth
                                loading={formik.isSubmitting}
                                icon={Search}
                            >
                                TRACK ORDER STATUS
                            </Button>
                        </form>
                    </div>
                )}

                {/* Result Card */}
                {trackingResult && (
                    <div className="tracking-result-card">
                        <div className="tracking-result-header">
                            <div>
                                <span className="tracking-order-id-label">
                                    ORDER ID
                                </span>
                                <h3 className="tracking-order-id-val">
                                    {trackingResult.order_number}
                                </h3>
                                <span className="tracking-order-date">
                                    Placed on: {trackingResult.created_at}
                                </span>
                            </div>
                            <div className="tracking-header-right">
                                <StatusBadge status={trackingResult.status} />
                                <div className="tracking-order-total">
                                    {formatBdt(trackingResult.total)}
                                </div>
                            </div>
                        </div>

                        {/* Interactive Timeline Stepper */}
                        <div className="tracking-stepper-container">
                            <div className="tracking-stepper-line">
                                <div
                                    className="tracking-stepper-line-progress"
                                    style={{ width: `${progressWidth}%` }}
                                />
                            </div>

                            {STEPS.map((step) => {
                                const Icon = step.icon;
                                const isCompleted = step.id < currentStep;
                                const isActive = step.id === currentStep;

                                return (
                                    <div
                                        key={step.id}
                                        className="tracking-step-node"
                                    >
                                        <div
                                            className={`tracking-step-circle ${isActive ? 'active' : ''} ${isCompleted ? 'completed' : ''}`}
                                        >
                                            {isCompleted ? (
                                                <CheckCircle2 size={18} />
                                            ) : (
                                                <Icon size={18} />
                                            )}
                                        </div>
                                        <span className="tracking-step-label">
                                            {step.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Status Message Highlight */}
                        <div className="tracking-status-highlight">
                            <strong className="tracking-status-label-strong">
                                Status: {trackingResult.status_label}
                            </strong>
                            <p className="tracking-status-desc-text">
                                {trackingResult.status_desc}
                            </p>
                        </div>

                        {/*
                         * Who has the parcel and how to chase it — the
                         * question this page exists to answer, and one it
                         * could not answer at all before couriers were
                         * recorded.
                         */}
                        {trackingResult.courier && (
                            <div className="tracking-courier-card">
                                <div className="tracking-courier-head">
                                    <Truck size={16} />
                                    <strong>{trackingResult.courier}</strong>
                                    {trackingResult.dispatched_at && (
                                        <span>
                                            Handed over{' '}
                                            {trackingResult.dispatched_at}
                                        </span>
                                    )}
                                </div>

                                {trackingResult.tracking_number && (
                                    <div className="tracking-courier-number">
                                        Consignment
                                        <code>
                                            {trackingResult.tracking_number}
                                        </code>
                                    </div>
                                )}

                                <div className="tracking-courier-actions">
                                    {trackingResult.tracking_url && (
                                        <a
                                            href={trackingResult.tracking_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="btn btn-outline btn-sm"
                                        >
                                            Track with {trackingResult.courier}
                                            <ExternalLink size={13} />
                                        </a>
                                    )}
                                    {trackingResult.courier_phone && (
                                        <a
                                            href={`tel:${trackingResult.courier_phone}`}
                                            className="btn btn-outline btn-sm"
                                        >
                                            <PhoneCall size={13} />
                                            {trackingResult.courier_phone}
                                        </a>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Info Grid: Address & Payment */}
                        <div className="tracking-info-grid">
                            <div className="tracking-info-item">
                                <strong>Recipient & Delivery Address</strong>
                                <span>
                                    {trackingResult.shipping_address?.name}
                                </span>
                                <div className="tracking-meta-icon-text">
                                    <MapPin size={12} />
                                    {
                                        trackingResult.shipping_address
                                            ?.street_address
                                    }
                                    , {trackingResult.shipping_address?.city}
                                </div>
                            </div>
                            <div className="tracking-info-item">
                                <strong>Payment & Method</strong>
                                <span>
                                    {trackingResult.payment_method} (
                                    {trackingResult.payment_status?.toUpperCase()}
                                    )
                                </span>
                                <div className="tracking-meta-icon-text">
                                    <CreditCard size={12} />
                                    Subtotal:{' '}
                                    {formatBdt(trackingResult.subtotal)} +
                                    Delivery:{' '}
                                    {Number(trackingResult.shipping_fee) === 0
                                        ? 'Free'
                                        : formatBdt(
                                              trackingResult.shipping_fee,
                                          )}
                                </div>
                            </div>
                        </div>

                        {/* Itemized Order List */}
                        <h4 className="tracking-items-heading">
                            Ordered Items ({trackingResult.items?.length})
                        </h4>
                        <div className="tracking-items-list">
                            {trackingResult.items?.map((item) => (
                                <div
                                    key={item.id}
                                    className="tracking-item-row"
                                >
                                    <div className="tracking-item-left">
                                        <img
                                            src={item.image}
                                            alt={item.product_name}
                                            className="tracking-item-thumb"
                                        />
                                        <div>
                                            <span className="tracking-item-name">
                                                {item.product_name}
                                                {item.variant_name
                                                    ? ` (${item.variant_name})`
                                                    : ''}
                                            </span>
                                            <span className="tracking-item-qty">
                                                Qty: {item.quantity} ×{' '}
                                                {formatBdt(item.price)}
                                            </span>
                                        </div>
                                    </div>
                                    <strong className="tracking-item-total-price">
                                        {formatBdt(item.total)}
                                    </strong>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
TrackOrder.layout = mainLayout;
