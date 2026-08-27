import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DollarSign,
    ShoppingCart,
    Truck,
    AlertTriangle,
    ArrowRight,
    TrendingUp,
} from 'lucide-react';
import { StatusBadge } from '@/Components/StatusBadge';
import { formatBdt, formatBdPhone } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';
import { adminService } from '@/services';
import ProductImage from '@/Components/ProductImage';
import { toast } from '@/Components/Toast';

export default function Dashboard({
    metrics = {},
    margin = null,
    profitAndLoss = null,
    recentOrders = [],
    lowStockProducts = [],
    queueHealth = null,
}) {
    const handleStatusChange = async (orderId, newStatus) => {
        try {
            await adminService.updateOrderStatus(orderId, newStatus);
            toast.success(
                `Order #${orderId} marked as ${newStatus.toUpperCase()}`,
                'Status Updated',
            );
            router.reload({ preserveScroll: true });
        } catch (error) {
            console.error('Failed to update status', error);
            toast.error(
                'Failed to update order status. Please try again.',
                'Update Error',
            );
        }
    };

    return (
        <AdminLayout
            title="Executive Overview"
            subtitle={`Real-time Revenue, Orders, and Stock Logistics for ${siteConfig.name}`}
        >
            <Head title={`Admin Operations Overview — ${siteConfig.name}`} />

            {/*
             * Queued mail fails quietly on purpose: an order is never held up
             * waiting for SMTP. The cost is that a dead worker looks exactly
             * like a working one from in here, so it gets said out loud.
             */}
            {queueHealth && !queueHealth.healthy && (
                <div className="admin-alert-banner" role="alert">
                    <AlertTriangle size={18} />
                    <div>
                        <strong>Customer emails are not going out.</strong>
                        <span>{queueHealth.message}</span>
                        <code>php artisan queue:work --tries=3</code>
                    </div>
                </div>
            )}

            {/* KPI Stat Cards */}
            <div className="admin-kpi-grid">
                {/*
                 * The shop's takings and its margin belong to whoever keeps
                 * the accounts. The server withholds both for everyone else,
                 * so these are absent rather than zero.
                 */}
                {metrics.total_revenue !== null &&
                    metrics.total_revenue !== undefined && (
                        <div className="admin-kpi-card">
                            <div className="admin-kpi-top">
                                <span className="admin-kpi-label">
                                    TOTAL GROSS SALES
                                </span>
                                <div className="admin-kpi-icon-box admin-kpi-icon-emerald">
                                    <DollarSign size={20} />
                                </div>
                            </div>
                            <div className="admin-kpi-val">
                                {formatBdt(metrics.total_revenue)}
                            </div>
                            <div className="admin-kpi-trend emerald">
                                <TrendingUp size={14} />
                                <span>All completed & active orders</span>
                            </div>
                        </div>
                    )}

                {/* Total Orders Card */}
                <div className="admin-kpi-card">
                    <div className="admin-kpi-top">
                        <span className="admin-kpi-label">TOTAL ORDERS</span>
                        <div className="admin-kpi-icon-box admin-kpi-icon-blue">
                            <ShoppingCart size={20} />
                        </div>
                    </div>
                    <div className="admin-kpi-val">
                        {metrics.total_orders || 0}
                    </div>
                    <div className="admin-kpi-trend blue">
                        <span>Across 64 Bangladesh districts</span>
                    </div>
                </div>

                {/* Pending Orders Card */}
                <div className="admin-kpi-card">
                    <div className="admin-kpi-top">
                        <span className="admin-kpi-label">
                            PENDING SHIPMENTS
                        </span>
                        <div className="admin-kpi-icon-box admin-kpi-icon-amber">
                            <Truck size={20} />
                        </div>
                    </div>
                    <div className="admin-kpi-val">
                        {metrics.pending_orders || 0}
                    </div>
                    <div className="admin-kpi-trend amber">
                        <span>Awaiting courier handover</span>
                    </div>
                </div>

                {/* Gross Margin Card */}
                {margin && (
                    <div className="admin-kpi-card">
                        <div className="admin-kpi-top">
                            <span className="admin-kpi-label">
                                GROSS MARGIN
                            </span>
                            <div className="admin-kpi-icon-box admin-kpi-icon-emerald">
                                <TrendingUp size={20} />
                            </div>
                        </div>
                        <div className="admin-kpi-val">
                            {margin?.orders_counted
                                ? formatBdt(margin.gross_profit)
                                : '—'}
                        </div>
                        <div className="admin-kpi-trend emerald">
                            {/*
                             * Deliberately explicit about what this is not. There
                             * are no expense records yet, so this is goods sold
                             * less what those goods cost — not profit. Orders with
                             * a line of unknown cost are left out rather than
                             * counted at a partial cost, and saying how many keeps
                             * the number from being read as the whole picture.
                             */}
                            <span>
                                {margin?.orders_counted
                                    ? `${margin.margin_percent ?? 0}% on ${margin.orders_counted} costed order${margin.orders_counted === 1 ? '' : 's'}`
                                    : 'No orders with a known cost yet'}
                                {margin?.orders_uncosted
                                    ? ` · ${margin.orders_uncosted} excluded`
                                    : ''}
                            </span>
                        </div>
                    </div>
                )}

                {/* Low Stock Alerts Card */}
                <div className="admin-kpi-card">
                    <div className="admin-kpi-top">
                        <span className="admin-kpi-label">LOW STOCK ITEMS</span>
                        <div className="admin-kpi-icon-box admin-kpi-icon-red">
                            <AlertTriangle size={20} />
                        </div>
                    </div>
                    <div className="admin-kpi-val">
                        {metrics.low_stock_count || 0}
                    </div>
                    <div className="admin-kpi-trend red">
                        <span>Stock below threshold (≤10 units)</span>
                    </div>
                </div>
            </div>

            {/*
             * This month so far, so the overview answers "are we making money"
             * without a trip to the report. Absent, not zero, for anyone
             * without the finance ability — the server does not compute it.
             */}
            {profitAndLoss && (
                <section className="admin-pl-card">
                    <header className="admin-pl-head">
                        <div>
                            <h2>Profit &amp; loss</h2>
                            <span>
                                This month so far ·{' '}
                                {profitAndLoss.orders_counted} costed order
                                {profitAndLoss.orders_counted === 1 ? '' : 's'}
                            </span>
                        </div>
                        <Link
                            href={ROUTES.ADMIN_REPORTS_PROFIT}
                            className="admin-pl-link"
                        >
                            Full report <ArrowRight size={14} />
                        </Link>
                    </header>

                    <div className="admin-pl-figures">
                        <div className="admin-pl-figure">
                            <span>Revenue</span>
                            <strong>{formatBdt(profitAndLoss.revenue)}</strong>
                        </div>
                        <div className="admin-pl-figure">
                            <span>Cost of goods</span>
                            <strong>
                                {formatBdt(profitAndLoss.cost_of_goods)}
                            </strong>
                        </div>
                        <div className="admin-pl-figure">
                            <span>Expenses</span>
                            <strong>{formatBdt(profitAndLoss.expenses)}</strong>
                        </div>
                        <div
                            className={`admin-pl-figure admin-pl-net ${profitAndLoss.net_profit < 0 ? 'is-loss' : 'is-profit'}`}
                        >
                            <span>
                                {profitAndLoss.net_profit < 0
                                    ? 'Net loss'
                                    : 'Net profit'}
                            </span>
                            <strong>
                                {formatBdt(profitAndLoss.net_profit)}
                            </strong>
                            {profitAndLoss.net_margin_percent !== null && (
                                <small>
                                    {profitAndLoss.net_margin_percent}% margin
                                </small>
                            )}
                        </div>
                    </div>

                    {/*
                     * An order whose cost is not known cannot be turned into
                     * profit, so it is left out — but saying nothing would let
                     * "৳0" read as "we sold nothing" at a shop that sold most
                     * of a million taka this month.
                     */}
                    {profitAndLoss.excluded?.orders > 0 && (
                        <p className="admin-pl-excluded">
                            <AlertTriangle size={13} />
                            {profitAndLoss.excluded.orders} order
                            {profitAndLoss.excluded.orders === 1
                                ? ''
                                : 's'}{' '}
                            worth {formatBdt(profitAndLoss.excluded.revenue)}{' '}
                            are not counted above, because what those goods cost
                            is not recorded. Receive stock with a unit cost and
                            they will appear here.
                        </p>
                    )}
                </section>
            )}

            {/* 2-Column Middle Grid: Recent Orders & Stock Alert */}
            <div className="admin-grid-2col">
                {/* Recent Orders Feed */}
                <div className="admin-card admin-card-no-margin">
                    <div className="admin-card-header">
                        <div>
                            <h3 className="admin-card-title">
                                Live Orders Feed
                            </h3>
                            <span className="admin-table-item-sub">
                                Latest purchases requiring processing & shipment
                            </span>
                        </div>
                        <Link
                            href={ROUTES.ADMIN_ORDERS}
                            className="admin-card-header-link"
                        >
                            <span>All Orders</span>
                            <ArrowRight size={14} />
                        </Link>
                    </div>

                    <div className="admin-table-responsive">
                        <table className="admin-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Quick Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentOrders.map((order) => (
                                    <tr key={order.id}>
                                        <td>
                                            <strong className="admin-table-item-title">
                                                #{order.order_number}
                                            </strong>
                                            <div className="admin-table-item-sub">
                                                {order.payment_method}
                                            </div>
                                        </td>
                                        <td>
                                            <div className="admin-table-item-title">
                                                {order.user?.name ||
                                                    order.shipping_address
                                                        ?.name ||
                                                    'Guest'}
                                            </div>
                                            <div className="admin-table-item-sub">
                                                {formatBdPhone(
                                                    order.user?.phone ||
                                                        order.shipping_address
                                                            ?.phone,
                                                )}
                                            </div>
                                        </td>
                                        <td>
                                            <strong className="admin-table-price-strong">
                                                {formatBdt(order.total)}
                                            </strong>
                                        </td>
                                        <td>
                                            <StatusBadge
                                                status={order.status}
                                            />
                                        </td>
                                        <td>
                                            <select
                                                value={order.status}
                                                onChange={(e) =>
                                                    handleStatusChange(
                                                        order.id,
                                                        e.target.value,
                                                    )
                                                }
                                                className="admin-status-dropdown"
                                            >
                                                <option value="pending">
                                                    Pending
                                                </option>
                                                <option value="processing">
                                                    Processing
                                                </option>
                                                <option value="shipped">
                                                    Shipped
                                                </option>
                                                <option value="delivered">
                                                    Delivered
                                                </option>
                                                <option value="cancelled">
                                                    Cancelled
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Low Stock Alerts Box */}
                <div className="admin-card admin-card-no-margin">
                    <div className="admin-card-header">
                        <div>
                            <h3 className="admin-card-title">
                                Inventory Alerts
                            </h3>
                            <span className="admin-table-item-sub">
                                Items needing replenishment
                            </span>
                        </div>
                        <Link
                            href={ROUTES.ADMIN_PRODUCTS}
                            className="admin-card-header-link"
                        >
                            Inventory
                        </Link>
                    </div>

                    <div className="admin-stock-alert-list">
                        {lowStockProducts.map((p) => (
                            <div key={p.id} className="admin-stock-alert-item">
                                <ProductImage
                                    product={p}
                                    alt={p.name}
                                    className="admin-stock-alert-img"
                                />
                                <div className="admin-stock-alert-info">
                                    <div className="admin-stock-alert-name">
                                        {p.name}
                                    </div>
                                    <div className="admin-stock-alert-qty">
                                        Only {p.stock_quantity} left in stock
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
