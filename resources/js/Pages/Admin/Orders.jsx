import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Eye, ShoppingCart, Undo2, Printer, Truck } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';
import { toast } from '@/Components/Toast';
import DispatchOrderModal from './Components/DispatchOrderModal';
import OrderReturnModal from './Components/OrderReturnModal';
import RefundOrderModal from './Components/RefundOrderModal';
import { adminService } from '@/services';
import { formatBdt, formatDate, formatBdPhone } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';
import {
    TERMINAL_ORDER_STATUSES,
    RETURNABLE_ORDER_STATUSES,
    CANCELLABLE_ORDER_STATUSES,
    orderStatusOptionsFor,
} from '@/constants';

export default function Orders({
    orders = { data: [] },
    currentStatus = 'all',
    search = '',
    couriers = [],
    refundMethods = [],
    refundReasons = [],
}) {
    const [searchTerm, setSearchTerm] = useState(search);
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [returningOrder, setReturningOrder] = useState(null);
    const [dispatchingOrder, setDispatchingOrder] = useState(null);
    const [refundingOrder, setRefundingOrder] = useState(null);

    const handleSearch = (term) => {
        setSearchTerm(term);
        router.get(
            ROUTES.ADMIN_ORDERS,
            {
                status: currentStatus,
                search: term,
            },
            {
                preserveState: true,
            },
        );
    };

    const handleFilterStatus = (status) => {
        router.get(
            ROUTES.ADMIN_ORDERS,
            {
                status: status,
                search: searchTerm,
            },
            {
                preserveState: true,
            },
        );
    };

    const handleStatusChange = async (orderId, newStatus) => {
        try {
            await adminService.updateOrderStatus(orderId, newStatus);
            toast.success(
                `Order #${orderId} status updated to ${newStatus.toUpperCase()}`,
                'Status Updated',
            );
            router.reload({ preserveScroll: true });
        } catch (error) {
            console.error('Failed to update order status', error);
            toast.error(
                'Failed to update order status. Please try again.',
                'Update Error',
            );
        }
    };

    const columns = [
        {
            key: 'order_number',
            header: 'Order ID',
            render: (order) => (
                <span className="admin-order-id-strong">
                    #{order.order_number}
                </span>
            ),
        },
        {
            key: 'customer',
            header: 'Customer',
            render: (order) => (
                <div>
                    <strong className="admin-order-user-name">
                        {order.user
                            ? order.user.name
                            : order.shipping_address?.name}
                    </strong>
                    <span className="admin-order-user-email">
                        {order.user?.email}
                    </span>
                </div>
            ),
        },
        {
            key: 'phone',
            header: 'Contact Phone',
            render: (order) => (
                <span className="admin-order-phone">
                    {formatBdPhone(
                        order.user?.phone || order.shipping_address?.phone,
                    )}
                </span>
            ),
        },
        {
            key: 'date',
            header: 'Date',
            render: (order) => (
                <span className="admin-order-date">
                    {formatDate(order.created_at)}
                </span>
            ),
        },
        {
            key: 'items',
            header: 'Items',
            render: (order) => (
                <span className="admin-order-items-count">
                    {order.items_count || order.items?.length || 1} Item(s)
                </span>
            ),
        },
        {
            key: 'total',
            header: 'Total BDT',
            render: (order) => (
                <strong className="admin-order-total-price">
                    {formatBdt(order.total)}
                </strong>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (order) => (
                <div className="admin-order-status-flex">
                    <StatusBadge status={order.status} />

                    {/*
                     * Cancelled and returned are end states. A returned order
                     * has had its units accounted for item by item; a cancelled
                     * one has handed them back. Either way there is nothing to
                     * move it to, so the select is a label.
                     *
                     * A delivered order keeps its select but loses "Cancelled":
                     * the goods are with the customer, so anything coming back
                     * is a return, which records what condition it arrived in.
                     */}
                    {TERMINAL_ORDER_STATUSES.includes(order.status) ? (
                        <span className="admin-field-hint">
                            {order.status === 'returned'
                                ? 'Returned'
                                : 'Cancelled'}
                        </span>
                    ) : (
                        <select
                            value={order.status}
                            onChange={(e) =>
                                handleStatusChange(order.id, e.target.value)
                            }
                            className="admin-status-dropdown"
                        >
                            {orderStatusOptionsFor(order.status).map(
                                (option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ),
                            )}
                        </select>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (order) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        onClick={() => setSelectedOrder(order)}
                        title="Inspect Order Details"
                    >
                        <Eye size={14} />
                    </button>

                    {/* Before dispatch, handing the parcel over is its own
                        step: it records who took it and the number to chase
                        it by, which a bare status change never did. */}
                    {CANCELLABLE_ORDER_STATUSES.includes(order.status) && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            onClick={() => setDispatchingOrder(order)}
                            title="Dispatch with a courier"
                        >
                            <Truck size={14} />
                        </button>
                    )}

                    {/* Once an order is dispatched its goods are outside the
                        building, so anything coming back — refused at the
                        door, recalled from the courier, or returned after
                        delivery — arrives through here, where what actually
                        turned up and its condition are recorded. */}
                    {RETURNABLE_ORDER_STATUSES.includes(order.status) && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            onClick={() => setReturningOrder(order)}
                            title="Process a return"
                        >
                            <Undo2 size={14} />
                        </button>
                    )}

                    {/* Money going back, which is a different event from goods
                        coming back: a damaged item may be refunded without
                        being returned, and an exchange returns goods without
                        refunding anything. */}
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        onClick={() => setRefundingOrder(order)}
                        title="Record a refund"
                    >
                        <Undo2 size={14} />
                    </button>

                    {/* Opens the print dialog straight away — the paperwork
                        goes out with the rider. */}
                    <a
                        href={`/orders/${order.id}/invoice?print=1`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="admin-table-icon-btn"
                        title="Print invoice"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <Printer size={14} />
                    </a>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Orders & Deliveries"
            subtitle={`Manage ${siteConfig.name} Nationwide Shipments & Payment Verifications`}
        >
            <Head title={`Admin Orders — ${siteConfig.name}`} />

            {/* Reusable Data Table */}
            <DataTable
                columns={columns}
                data={orders}
                keyField="id"
                title="Customer Orders"
                subtitle="Live customer purchases, delivery statuses, and invoices"
                searchable
                searchValue={searchTerm}
                onSearch={handleSearch}
                searchPlaceholder="Search by order #, name, phone..."
                emptyIcon={ShoppingCart}
                emptyTitle="No Orders Found"
                emptyDescription="There are no customer orders matching the current filter criteria."
                headerActions={
                    <div className="admin-tabs-bar">
                        {[
                            'all',
                            'pending',
                            'processing',
                            'shipped',
                            'delivered',
                            'cancelled',
                        ].map((st) => (
                            <button
                                key={st}
                                type="button"
                                onClick={() => handleFilterStatus(st)}
                                className={`admin-tab-btn ${currentStatus === st ? 'active' : ''}`}
                            >
                                {st}
                            </button>
                        ))}
                    </div>
                }
            />

            {/* Order Inspection Modal */}
            <Modal
                isOpen={Boolean(selectedOrder)}
                onClose={() => setSelectedOrder(null)}
                title={selectedOrder?.order_number || 'Order'}
                maxWidth="680px"
                footer={
                    <>
                        <a
                            href={`/orders/${selectedOrder?.id}/invoice?print=1`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="btn btn-outline"
                        >
                            <Printer size={15} /> Print invoice
                        </a>
                        <Button onClick={() => setSelectedOrder(null)}>
                            Close
                        </Button>
                    </>
                }
            >
                {selectedOrder && (
                    <div>
                        {/* Status Bar */}
                        <div className="admin-modal-inspect-header">
                            <div>
                                <span className="admin-modal-inspect-date">
                                    Placed on{' '}
                                    {formatDate(selectedOrder.created_at)}
                                </span>
                                <div className="admin-modal-inspect-amount">
                                    {formatBdt(selectedOrder.total)}
                                </div>
                            </div>
                            <StatusBadge status={selectedOrder.status} />
                        </div>

                        {/* Customer & Shipping Summary */}
                        <div className="admin-detail-panel">
                            <span className="admin-detail-panel-label">
                                Shipping &amp; customer
                            </span>

                            <dl className="admin-detail-grid">
                                <div>
                                    <dt>Recipient</dt>
                                    <dd>
                                        {selectedOrder.shipping_address?.name ||
                                            selectedOrder.user?.name ||
                                            '—'}
                                    </dd>
                                </div>

                                <div>
                                    <dt>Phone</dt>
                                    <dd>
                                        {formatBdPhone(
                                            selectedOrder.shipping_address
                                                ?.phone ||
                                                selectedOrder.user?.phone,
                                        ) || '—'}
                                    </dd>
                                </div>

                                <div className="admin-detail-wide">
                                    <dt>Deliver to</dt>
                                    <dd>
                                        {[
                                            selectedOrder.shipping_address
                                                ?.street_address ||
                                                selectedOrder.shipping_address
                                                    ?.address,
                                            selectedOrder.shipping_address
                                                ?.zone,
                                            selectedOrder.shipping_address
                                                ?.city,
                                        ]
                                            .filter(Boolean)
                                            .join(', ') || '—'}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        {/* Order Items Table */}
                        <h4 className="admin-modal-items-title">
                            Purchased Hardware Items
                        </h4>
                        <table className="admin-modal-items-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Unit Price</th>
                                    <th>Qty</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {selectedOrder.items?.map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <strong>
                                                {item.product?.name ||
                                                    item.product_name ||
                                                    'Hardware Component'}
                                            </strong>
                                            {/* Frozen on the line at purchase
                                                time, so it still reads correctly
                                                if the option is renamed later. */}
                                            {item.variant_name && (
                                                <div className="admin-field-hint">
                                                    {item.variant_name}
                                                </div>
                                            )}
                                        </td>
                                        <td>{formatBdt(item.price)}</td>
                                        <td>× {item.quantity}</td>
                                        <td>
                                            <strong>
                                                {formatBdt(
                                                    item.price * item.quantity,
                                                )}
                                            </strong>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Modal>
            <DispatchOrderModal
                order={dispatchingOrder}
                couriers={couriers}
                onClose={() => setDispatchingOrder(null)}
                onDone={() => {
                    setDispatchingOrder(null);
                    router.reload({ only: ['orders'] });
                }}
            />

            <RefundOrderModal
                order={refundingOrder}
                methods={refundMethods}
                reasons={refundReasons}
                onClose={() => setRefundingOrder(null)}
                onDone={() => {
                    setRefundingOrder(null);
                    router.reload({ only: ['orders'] });
                }}
            />

            <OrderReturnModal
                order={returningOrder}
                onClose={() => setReturningOrder(null)}
                onSaved={() => {
                    setReturningOrder(null);
                    router.reload({ preserveScroll: true });
                }}
            />
        </AdminLayout>
    );
}
