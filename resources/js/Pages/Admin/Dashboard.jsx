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
import { ProductImage, toast } from '@/Components';

export default function Dashboard({
    metrics = {},
    recentOrders = [],
    lowStockProducts = [],
    topSelling = [],
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
                {/* Revenue Card */}
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
