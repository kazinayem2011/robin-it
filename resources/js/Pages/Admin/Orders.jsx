import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Eye, ShoppingCart, Undo2 } from 'lucide-react';
import { StatusBadge, Modal, Button, DataTable, toast } from '@/Components';
import OrderReturnModal from './Components/OrderReturnModal';
import { adminService } from '@/services';
import { formatBdt, formatDate, formatBdPhone } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';

export default function Orders({
    orders = { data: [] },
    currentStatus = 'all',
    search = '',
}) {
    const [searchTerm, setSearchTerm] = useState(search);
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [returningOrder, setReturningOrder] = useState(null);

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
                     * A returned order is finished: its units have already been
                     * accounted for item by item, so moving it on again would
                     * double-count them.
                     */}
                    {order.status === 'returned' ? (
                        <span className="admin-field-hint">Returned</span>
                    ) : (
                        <select
                            value={order.status}
                            onChange={(e) =>
                                handleStatusChange(order.id, e.target.value)
                            }
                            className="admin-status-dropdown"
                        >
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="shipped">Shipped</option>
                            <option value="delivered">Delivered</option>
                            <option value="cancelled">Cancelled</option>
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

                    {/* Only a delivered order has goods with the customer to
                        take back. */}
                    {order.status === 'delivered' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            onClick={() => setReturningOrder(order)}
                            title="Process a return"
                        >
                            <Undo2 size={14} />
                        </button>
                    )}
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
                title={`Order Inspector: #${selectedOrder?.order_number || ''}`}
                maxWidth="680px"
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
                                    Total: {formatBdt(selectedOrder.total)}
                                </div>
                            </div>
                            <StatusBadge status={selectedOrder.status} />
                        </div>

                        {/* Customer & Shipping Summary */}
                        <div className="admin-modal-shipping-box">
                            <h4 className="admin-modal-shipping-title">
                                Shipping & Customer Details
                            </h4>
                            <div className="admin-modal-shipping-grid">
                                <div>
                                    <div className="admin-modal-shipping-label">
                                        Recipient:
                                    </div>
                                    <div className="admin-modal-shipping-val">
                                        {selectedOrder.shipping_address?.name ||
                                            selectedOrder.user?.name ||
                                            'N/A'}
                                    </div>
                                    <div className="admin-modal-shipping-label mt-6">
                                        Contact Phone:
                                    </div>
                                    <div className="admin-modal-shipping-val">
                                        {formatBdPhone(
                                            selectedOrder.shipping_address
                                                ?.phone ||
                                                selectedOrder.user?.phone,
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <div className="admin-modal-shipping-label">
                                        Delivery Destination:
                                    </div>
                                    <div className="admin-modal-shipping-val">
                                        {selectedOrder.shipping_address
                                            ?.street_address ||
                                            selectedOrder.shipping_address
                                                ?.address ||
                                            'Dhaka, Bangladesh'}
                                    </div>
                                    <div className="admin-modal-shipping-val">
                                        {selectedOrder.shipping_address?.city ||
                                            'Dhaka'}
                                        ,{' '}
                                        {selectedOrder.shipping_address
                                            ?.district || 'Dhaka'}
                                    </div>
                                </div>
                            </div>
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

                        {/* Modal Footer Actions */}
                        <div className="admin-modal-footer-btns">
                            <Button
                                variant="outline"
                                onClick={() => setSelectedOrder(null)}
                            >
                                Close Inspector
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>
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
