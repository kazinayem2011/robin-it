import React, { useState, useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import { Eye, XCircle } from 'lucide-react';
import AccountLayout from './AccountLayout';
import OrderInvoiceModal from './OrderInvoiceModal';
import { StatusBadge } from '@/Components/StatusBadge';
import { ProductImage } from '@/Components/ProductImage';
import { Pagination } from '@/Components/Pagination';
import { formatBdt, formatDate } from '@/utils/formatters';
import { ROUTES, API_ENDPOINTS } from '@/constants/endpoints';
import { mainLayout } from '../../Layouts/MainLayout';

export default function Orders({
    user,
    navCounts,
    techPoints,
    orders,
    focusOrder = null,
}) {
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [cancellingId, setCancellingId] = useState(null);

    // The history is paged now, so `orders` is a paginator rather than an array.
    const rows = orders?.data ?? [];

    /*
     * The overview links here with ?order=<id> when someone asks to see one in
     * full. On the single-page version that was a tab switch carrying the order
     * in local state; across a real navigation it has to travel in the URL.
     *
     * The server resolves it, because the order asked for may sit on any page
     * of the history — searching the rows this page happens to hold would open
     * nothing for anyone past their tenth order.
     */
    useEffect(() => {
        if (focusOrder) setSelectedOrder(focusOrder);
    }, [focusOrder]);

    // Mirrors Order::isCancellableByCustomer() — cancellable until it ships.
    const isCancellable = (order) =>
        ['pending', 'processing'].includes(order.status);

    const cancelOrder = (order) => {
        if (
            !window.confirm(
                `Cancel order #${order.order_number}? The items will be returned to stock.`,
            )
        ) {
            return;
        }

        setCancellingId(order.id);
        router.post(
            API_ENDPOINTS.ACCOUNT.ORDER_CANCEL(order.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setCancellingId(null),
            },
        );
    };

    return (
        <AccountLayout
            title="My Orders"
            active="orders"
            user={user}
            navCounts={navCounts}
            techPoints={techPoints}
        >
            <div>
                <div className="dash-tab-header">
                    <div>
                        <h2>My Hardware Orders</h2>
                        <p>
                            Track your active shipments, delivery status, and
                            invoices.
                        </p>
                    </div>
                </div>

                {rows.length === 0 ? (
                    <div className="dash-empty-box">
                        <p className="dash-empty-text">
                            You haven't placed any orders yet.
                        </p>
                        <Link
                            href={ROUTES.SHOP}
                            className="btn btn-primary btn-sm mt-3"
                        >
                            Browse Products
                        </Link>
                    </div>
                ) : (
                    <div className="orders-list-wrapper">
                        {rows.map((order) => (
                            <div key={order.id} className="order-history-card">
                                <div className="order-card-header">
                                    <div>
                                        <span className="dash-order-num-label">
                                            Order Number:{' '}
                                        </span>
                                        <strong className="dash-order-num-strong">
                                            #{order.order_number}
                                        </strong>
                                        <span className="dash-order-divider">
                                            |
                                        </span>
                                        <span className="dash-order-date">
                                            {formatDate(order.created_at)}
                                        </span>
                                    </div>
                                    <StatusBadge status={order.status} />
                                </div>

                                <div className="order-card-body">
                                    <div className="order-items-grid">
                                        {order.items?.map((item) => (
                                            <div
                                                key={item.id}
                                                className="order-item-row"
                                            >
                                                <ProductImage
                                                    product={item.product}
                                                    alt={item.product_name}
                                                    className="order-item-thumb"
                                                />
                                                <div className="order-item-details">
                                                    <div className="order-item-name">
                                                        {item.product_name}
                                                        {item.variant_name
                                                            ? ` (${item.variant_name})`
                                                            : ''}
                                                    </div>
                                                    <div className="order-item-sub">
                                                        Qty: {item.quantity} ×{' '}
                                                        {formatBdt(item.price)}
                                                    </div>
                                                </div>
                                                <div className="order-item-price">
                                                    {formatBdt(item.total)}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="order-card-footer">
                                    <div>
                                        <span className="dash-order-total-label">
                                            Total:{' '}
                                        </span>
                                        <strong className="dash-order-total-val">
                                            {formatBdt(order.total)}
                                        </strong>
                                    </div>
                                    <div className="dash-order-actions-row">
                                        <button
                                            className="btn btn-outline btn-sm"
                                            onClick={() =>
                                                setSelectedOrder(order)
                                            }
                                        >
                                            <Eye size={14} /> Order Invoice
                                        </button>
                                        {isCancellable(order) && (
                                            <button
                                                className="btn btn-outline btn-sm dash-order-cancel-btn"
                                                disabled={
                                                    cancellingId === order.id
                                                }
                                                onClick={() =>
                                                    cancelOrder(order)
                                                }
                                            >
                                                <XCircle size={14} />{' '}
                                                {cancellingId === order.id
                                                    ? 'Cancelling…'
                                                    : 'Cancel Order'}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {orders?.links?.length > 3 && (
                    <Pagination
                        links={orders.links}
                        from={orders.from}
                        to={orders.to}
                        total={orders.total}
                    />
                )}
            </div>

            <OrderInvoiceModal
                selectedOrder={selectedOrder}
                setSelectedOrder={setSelectedOrder}
            />
        </AccountLayout>
    );
}

// Persistent shell: mounts once, survives navigation.
Orders.layout = mainLayout;
