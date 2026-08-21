import { Head, Link } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import { ROUTES } from '../../constants/endpoints';
import './Checkout.css';

export default function Success({ orderNumber }) {
    return (
        <MainLayout>
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

                <div className="order-success-cta-row">
                    <Link
                        href={ROUTES.HOME}
                        className="btn btn-secondary hover-lift"
                    >
                        Return Home
                    </Link>
                    <Link
                        href={ROUTES.TRACK}
                        className="btn btn-primary hover-lift"
                    >
                        Track Order
                    </Link>
                </div>
            </div>
        </MainLayout>
    );
}
