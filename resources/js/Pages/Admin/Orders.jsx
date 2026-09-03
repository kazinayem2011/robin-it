import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    CircleDollarSign,
    Eye,
    PencilLine,
    Plus,
    Printer,
    ShoppingCart,
    Truck,
    Undo2,
    Wallet,
} from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';
import { toast } from '@/Components/Toast';
import DispatchOrderModal from './Components/DispatchOrderModal';
import OrderReturnModal from './Components/OrderReturnModal';
import EditOrderModal from './Components/EditOrderModal';
import NewOrderModal from './Components/NewOrderModal';
// The edit modal reuses the purchase-order line table.
import './Purchasing.css';
import RecordPaymentModal from './Components/RecordPaymentModal';
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

/*
 * What an order has actually collected.
 *
 * The list and the order both answer "is the money in", and answering it
 * twice is how two screens come to disagree about the same order.
 */
const paidOn = (order) =>
    (order?.payments ?? []).reduce((sum, p) => sum + Number(p.amount || 0), 0);

const refundedOn = (order) =>
    (order?.refunds ?? []).reduce((sum, r) => sum + Number(r.amount || 0), 0);

const dueOn = (order) =>
    Math.max(
        0,
        Number(order?.total || 0) - (paidOn(order) - refundedOn(order)),
    );

export default function Orders({
    orders = { data: [] },
    currentStatus = 'all',
    search = '',
    couriers = [],
    refundMethods = [],
    paymentMethods = [],
    refundReasons = [],
}) {
    const [searchTerm, setSearchTerm] = useState(search);
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [returningOrder, setReturningOrder] = useState(null);
    const [dispatchingOrder, setDispatchingOrder] = useState(null);
    const [refundingOrder, setRefundingOrder] = useState(null);
    const [payingOrder, setPayingOrder] = useState(null);
    const [editingOrder, setEditingOrder] = useState(null);
    const [takingOrder, setTakingOrder] = useState(false);

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
            /* The order on screen came from the list, so it is stale the
               moment the status changes. Close it and refresh rather than
               leave a panel showing the status it used to have. */
            setSelectedOrder(null);
            router.reload({ preserveScroll: true });
        } catch (error) {
            console.error('Failed to update order status', error);
            toast.error(
                'Failed to update order status. Please try again.',
                'Update Error',
            );
        }
    };

    /*
     * Five columns, not eight.
     *
     * The list carried the order number, the customer, their phone, the date,
     * an item count, the total, a status badge beside a status dropdown, and
     * seven action buttons — two of which used the same icon. Nothing could be
     * found at a glance because everything was present at once.
     *
     * What stays is what the list is read for: which order, whose, when, what
     * it is worth and whether the money is in, and where it has got to.
     * Everything else is one press away in the order itself, which is where it
     * can be given room.
     */
    const columns = [
        {
            key: 'order_number',
            header: 'Order',
            render: (order) => (
                <div>
                    <span className="admin-order-id-strong">
                        #{order.order_number}
                    </span>
                    {/* Under the number rather than in a column of its own: the
                        date is how you place an order in time, not something
                        you scan a column of. */}
                    <div className="admin-order-date">
                        {formatDate(order.created_at)}
                    </div>
                </div>
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
                    {/* The number, not the address. This shop rings people;
                        the email was a column nobody acted on. */}
                    <div className="admin-order-phone">
                        {formatBdPhone(
                            order.user?.phone || order.shipping_address?.phone,
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'total',
            header: 'Total BDT',
            render: (order) => {
                /*
                 * What is owed, beside what it came to. A total on its own says
                 * nothing about whether the shop has the money: a ৳2,45,000
                 * order with a ৳20,000 deposit against it looked exactly like
                 * one nobody had paid a taka on.
                 */
                const paid = paidOn(order);
                const due = dueOn(order);

                return (
                    <div>
                        <strong className="admin-order-total-price">
                            {formatBdt(order.total)}
                        </strong>
                        {due > 0 ? (
                            <div className="admin-order-due">
                                {paid > 0
                                    ? `${formatBdt(due)} still owed`
                                    : 'Nothing paid yet'}
                            </div>
                        ) : (
                            <div className="admin-order-settled">Paid</div>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'status',
            header: 'Status',
            /* The badge alone. The dropdown that sat beside it moved into the
               order, where changing a status is a decision rather than
               something done in passing while scanning a list. */
            render: (order) => <StatusBadge status={order.status} />,
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            /*
             * Two, from seven.
             *
             * Dispatch, return, payment, refund and edit were all here at
             * once, in a row narrow enough that two of them shared the Undo2
             * icon — one meaning goods coming back and the other money going
             * back. They live in the order now, spelled out in words.
             *
             * The invoice stays, because printing one is the errand you run
             * from the list itself, without needing to look at the order.
             */
            render: (order) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        onClick={() => setSelectedOrder(order)}
                        title="Open this order"
                    >
                        <Eye size={14} />
                    </button>

                    <a
                        href={`/orders/${order.id}/invoice?print=1`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="admin-table-icon-btn"
                        title="Print the invoice"
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
                    <>
                        {/* Filters first, action last — the same order as
                            every other screen, so the primary button is
                            always the rightmost thing in the bar. */}
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

                        {/*
                         * Taking an order from the counter or the phone. The
                         * shop could only ever receive one through the
                         * storefront, so a customer ringing up had to be sent
                         * to the website.
                         */}
                        <Button
                            variant="primary"
                            icon={Plus}
                            onClick={() => setTakingOrder(true)}
                        >
                            Take an order
                        </Button>
                    </>
                }
            />

            {/* Order Inspection Modal */}
            <Modal
                isOpen={Boolean(selectedOrder)}
                onClose={() => setSelectedOrder(null)}
                title={selectedOrder?.order_number || 'Order'}
                maxWidth="680px"
                footer={
                    /*
                     * Everything that can be done to this order, named.
                     *
                     * These were seven icons in a table row, where two shared
                     * a glyph and none had room for a word. Here each is what
                     * it does, and only the ones that apply to this order's
                     * state are offered — which is the same rule the row used,
                     * simply legible now.
                     */
                    <div className="admin-order-actions-footer">
                        <div className="admin-order-actions-doing">
                            {CANCELLABLE_ORDER_STATUSES.includes(
                                selectedOrder?.status,
                            ) && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Truck}
                                    onClick={() => {
                                        setDispatchingOrder(selectedOrder);
                                        setSelectedOrder(null);
                                    }}
                                >
                                    Dispatch
                                </Button>
                            )}

                            {RETURNABLE_ORDER_STATUSES.includes(
                                selectedOrder?.status,
                            ) && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Undo2}
                                    onClick={() => {
                                        setReturningOrder(selectedOrder);
                                        setSelectedOrder(null);
                                    }}
                                >
                                    Goods back
                                </Button>
                            )}

                            <Button
                                variant="secondary"
                                size="sm"
                                icon={Wallet}
                                onClick={() => {
                                    setPayingOrder(selectedOrder);
                                    setSelectedOrder(null);
                                }}
                            >
                                Payment in
                            </Button>

                            {/* Money going back is a different event from
                                goods coming back: a damaged item may be
                                refunded without being returned, and an
                                exchange returns goods without refunding. They
                                shared an icon in the row, which said the
                                opposite. */}
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={CircleDollarSign}
                                onClick={() => {
                                    setRefundingOrder(selectedOrder);
                                    setSelectedOrder(null);
                                }}
                            >
                                Money back
                            </Button>

                            {['pending', 'processing'].includes(
                                selectedOrder?.status,
                            ) && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={PencilLine}
                                    onClick={() => {
                                        setEditingOrder(selectedOrder);
                                        setSelectedOrder(null);
                                    }}
                                >
                                    Edit items
                                </Button>
                            )}

                            <a
                                href={`/orders/${selectedOrder?.id}/invoice?print=1`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn-secondary btn-sm"
                            >
                                <Printer size={15} /> Invoice
                            </a>
                        </div>

                        <Button onClick={() => setSelectedOrder(null)}>
                            Close
                        </Button>
                    </div>
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

                            {/*
                             * Where the order is, and where it goes next.
                             *
                             * This was a dropdown in the table, next to the
                             * badge, on every row — so the most consequential
                             * control on the screen sat in the column you
                             * scroll past, one mis-click from marking the
                             * wrong order delivered. It belongs with the
                             * order, next to what it is about to change.
                             *
                             * Cancelled and returned are end states: a
                             * returned order has had its units accounted for
                             * item by item, a cancelled one has handed them
                             * back, and there is nowhere to move either. A
                             * delivered order keeps the control but loses
                             * "Cancelled" — the goods are with the customer,
                             * so anything coming back is a return, which
                             * records what condition it arrived in.
                             */}
                            <div className="admin-order-status-control">
                                <StatusBadge status={selectedOrder.status} />

                                {TERMINAL_ORDER_STATUSES.includes(
                                    selectedOrder.status,
                                ) ? (
                                    <span className="admin-field-hint">
                                        {selectedOrder.status === 'returned'
                                            ? 'Returned — nothing further to move'
                                            : 'Cancelled — nothing further to move'}
                                    </span>
                                ) : (
                                    <label className="admin-order-status-move">
                                        <span className="admin-field-hint">
                                            Move to
                                        </span>
                                        <select
                                            value={selectedOrder.status}
                                            onChange={(e) =>
                                                handleStatusChange(
                                                    selectedOrder.id,
                                                    e.target.value,
                                                )
                                            }
                                            className="admin-status-dropdown"
                                        >
                                            {orderStatusOptionsFor(
                                                selectedOrder.status,
                                            ).map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                            </div>
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

                        {/*
                         * What has actually been collected.
                         *
                         * This arithmetic existed only in the list's total
                         * cell, so the order itself — the place you open to
                         * find out about it — could tell you what it came to
                         * and not a thing about whether the shop had the
                         * money. Every payment and every refund is listed,
                         * because "৳20,000 owed" invites the question of what
                         * came in and when.
                         */}
                        <div className="admin-detail-panel">
                            <span className="admin-detail-panel-label">
                                Money
                            </span>

                            <dl className="admin-detail-grid">
                                <div>
                                    <dt>Order total</dt>
                                    <dd>{formatBdt(selectedOrder.total)}</dd>
                                </div>
                                <div>
                                    <dt>Paid</dt>
                                    <dd>{formatBdt(paidOn(selectedOrder))}</dd>
                                </div>
                                <div>
                                    <dt>Refunded</dt>
                                    <dd>
                                        {formatBdt(refundedOn(selectedOrder))}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Outstanding</dt>
                                    <dd>
                                        {dueOn(selectedOrder) > 0 ? (
                                            <strong className="admin-order-due">
                                                {formatBdt(
                                                    dueOn(selectedOrder),
                                                )}
                                            </strong>
                                        ) : (
                                            <span className="admin-order-settled">
                                                Settled
                                            </span>
                                        )}
                                    </dd>
                                </div>
                            </dl>

                            {(selectedOrder.payments ?? []).length > 0 && (
                                <ul className="admin-money-log">
                                    {selectedOrder.payments.map((p) => (
                                        <li key={`p-${p.id}`}>
                                            <span>
                                                {formatDate(p.created_at)} ·{' '}
                                                {p.method ?? 'Payment'}
                                            </span>
                                            <span className="admin-money-in">
                                                +{formatBdt(p.amount)}
                                            </span>
                                        </li>
                                    ))}
                                    {(selectedOrder.refunds ?? []).map((r) => (
                                        <li key={`r-${r.id}`}>
                                            <span>
                                                {formatDate(r.created_at)} ·{' '}
                                                {r.reason ?? 'Refund'}
                                            </span>
                                            <span className="admin-money-out">
                                                −{formatBdt(r.amount)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
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

            <NewOrderModal
                open={takingOrder}
                onClose={() => setTakingOrder(false)}
                onCreated={() => {
                    setTakingOrder(false);
                    router.reload({ only: ['orders'] });
                }}
            />

            <EditOrderModal
                order={editingOrder}
                onClose={() => setEditingOrder(null)}
                onDone={() => {
                    setEditingOrder(null);
                    router.reload({ only: ['orders'] });
                }}
            />

            <RecordPaymentModal
                order={payingOrder}
                methods={paymentMethods}
                onClose={() => setPayingOrder(null)}
                onDone={() => {
                    setPayingOrder(null);
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
