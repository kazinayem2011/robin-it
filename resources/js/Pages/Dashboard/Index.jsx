import React from 'react';
import { Link, router } from '@inertiajs/react';
import { Award, CreditCard, Package, Truck } from 'lucide-react';
import AccountLayout from './AccountLayout';
import { StatusBadge } from '@/Components/StatusBadge';
import { ProductImage } from '@/Components/ProductImage';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';

export default function Index({
    user,
    navCounts,
    techPoints,
    recentOrders = [],
    stats = {},
}) {
    return (
        <AccountLayout
            title="My Account"
            active="overview"
            user={user}
            navCounts={navCounts}
            techPoints={techPoints}
        >
            <div>
                <div className="dash-tab-header">
                    <div>
                        <h2>Account Summary</h2>
                        <p>
                            Welcome back, {user.name}! Here is a summary of your
                            hardware orders & perks.
                        </p>
                    </div>
                </div>

                {/* Stat Tiles */}
                <div className="dash-stat-tiles-grid">
                    <div className="dash-kpi-card">
                        <div className="dash-kpi-icon bg-blue">
                            <Package size={22} />
                        </div>
                        <div className="dash-kpi-info">
                            <span>TOTAL ORDERS</span>
                            <strong>{stats.total_orders || 0}</strong>
                        </div>
                    </div>

                    <div className="dash-kpi-card">
                        <div className="dash-kpi-icon bg-amber">
                            <Truck size={22} />
                        </div>
                        <div className="dash-kpi-info">
                            <span>PENDING SHIPMENTS</span>
                            <strong>{stats.pending_orders || 0}</strong>
                        </div>
                    </div>

                    <div className="dash-kpi-card">
                        <div className="dash-kpi-icon bg-emerald">
                            <CreditCard size={22} />
                        </div>
                        <div className="dash-kpi-info">
                            <span>TOTAL PURCHASES</span>
                            <strong>{formatBdt(stats.total_spent)}</strong>
                        </div>
                    </div>

                    <div className="dash-kpi-card">
                        <div className="dash-kpi-icon bg-purple">
                            <Award size={22} />
                        </div>
                        <div className="dash-kpi-info">
                            <span>TECHCLUB POINTS</span>
                            <strong>{stats.tech_points || 0} pts</strong>
                        </div>
                    </div>
                </div>

                {/* Recent Orders Snapshot */}
                <h3 className="dash-section-title">Recent Order Activity</h3>
                {recentOrders.length === 0 ? (
                    <div className="dash-empty-box">
                        <Package size={40} className="dash-empty-icon" />
                        <p className="dash-empty-text">No orders placed yet.</p>
                        <Link
                            href={ROUTES.SHOP}
                            className="btn btn-primary btn-sm mt-3"
                        >
                            Start Shopping
                        </Link>
                    </div>
                ) : (
                    <div className="orders-list-wrapper">
                        {recentOrders.slice(0, 2).map((order) => (
                            <div key={order.id} className="order-history-card">
                                <div className="order-card-header">
                                    <div className="order-num-tag">
                                        <span>ORDER </span>
                                        <strong>#{order.order_number}</strong>
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
                                    <span className="dash-order-total-label">
                                        Payment:{' '}
                                        <strong>{order.payment_method}</strong>{' '}
                                        ({order.payment_status})
                                    </span>
                                    <button
                                        className="btn btn-outline btn-sm"
                                        onClick={() =>
                                            router.visit(
                                                `${ROUTES.DASHBOARD_ORDERS}?order=${order.id}`,
                                            )
                                        }
                                    >
                                        View Full Details
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AccountLayout>
    );
}
