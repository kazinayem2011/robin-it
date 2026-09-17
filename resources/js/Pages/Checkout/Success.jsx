import { Head, Link, usePage } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import { ROUTES } from '../../constants/endpoints';
import './Checkout.css';
import ProductSuggestions from '../../Components/ProductSuggestions';

/**
 * @param trackUrl The order's unlocked tracking link, given only to whoever
 *                 placed it, so "Track Order" opens the order for a guest too.
 * @param accountIsNew Checkout made them an account and signed them in. Saying
 *                 so here is the one moment they are certain to be looking;
 *                 the welcome email and the text say it again, and none of
 *                 them carries a password, because the account has none.
 */
export default function Success({
    orderNumber,
    trackUrl = null,
    accountIsNew = false,
    suggestions = [],
}) {
    const customer = usePage().props?.auth?.user ?? null;

    return (
        <>
            <Head title="Order Successful - StarTech Clone" />

            <div className="checkout-container order-success-container container">
                <div className="order-success-icon-box">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="40"
                        height="40"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="3"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>

                <h1 className="order-success-title">
                    Order Placed Successfully!
                </h1>
                <p className="order-success-msg">
                    Thank you for shopping with us. Your order{' '}
                    <strong className="order-success-order-num">
                        #{orderNumber}
                    </strong>{' '}
                    has been received.
                </p>

                {accountIsNew && (
                    <div className="order-success-account">
                        <strong>
                            We have created an account for{' '}
                            {customer?.phone || 'you'}.
                        </strong>{' '}
                        You are signed in on this device. Set a password to sign
                        in on another one — we never send passwords by text or
                        email.{' '}
                        <Link href={ROUTES.DASHBOARD_PROFILE}>
                            Set a password
                        </Link>
                    </div>
                )}

                <div className="order-success-cta-row">
                    <Link href={ROUTES.HOME} className="btn btn-secondary">
                        Return Home
                    </Link>
                    {/* Carrying the number means the page opens on this order
                        rather than on an empty form asking for something the
                        customer was just shown. The server hands over the
                        order's own key only to whoever placed it, so that link
                        opens the order outright; anyone else arriving here is
                        asked for the mobile on it, which proves it is theirs. */}
                    <Link
                        href={
                            trackUrl ||
                            (orderNumber
                                ? `${ROUTES.TRACK}/${encodeURIComponent(orderNumber)}`
                                : ROUTES.TRACK)
                        }
                        className="btn btn-primary"
                    >
                        Track Order
                    </Link>
                </div>
                <ProductSuggestions
                    products={suggestions}
                    title="You might like these too"
                />
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
Success.layout = mainLayout;
