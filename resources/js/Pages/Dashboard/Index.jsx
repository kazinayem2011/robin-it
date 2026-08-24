import React, { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import MainLayout from '@/Layouts/MainLayout';
import {
    User,
    Package,
    Heart,
    MapPin,
    Lock,
    LogOut,
    ShieldCheck,
    Clock,
    CheckCircle,
    Truck,
    AlertCircle,
    CreditCard,
    Plus,
    Trash2,
    Edit2,
    ArrowRight,
    Phone,
    Mail,
    Sparkles,
    Award,
    Eye,
    ChevronRight,
    XCircle,
    Printer,
} from 'lucide-react';
import { StatusBadge } from '@/Components/StatusBadge';
import { Modal } from '@/Components/Modal';
import { ProductImage } from '@/Components/ProductImage';
import { formatBdt, formatDate, formatBdPhone } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES, API_ENDPOINTS } from '@/constants/endpoints';
import './Dashboard.css';

export default function Index({
    user,
    orders = [],
    addresses = [],
    wishlistItems = [],
    stats = {},
}) {
    const [activeTab, setActiveTab] = useState('overview');
    const [selectedOrder, setSelectedOrder] = useState(null);
    const [cancellingId, setCancellingId] = useState(null);

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
    const [showAddressModal, setShowAddressModal] = useState(false);

    // Profile form state
    const profileForm = useForm({
        name: user.name || '',
        email: user.email || '',
        phone: user.phone || '',
    });

    // Password form state
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // New address form state
    const addressForm = useForm({
        name: user.name || '',
        phone: user.phone || '',
        division: 'Dhaka',
        district: 'Dhaka',
        city: '',
        address: '',
        is_default: true,
    });

    const handleProfileSubmit = (e) => {
        e.preventDefault();
        profileForm.post(API_ENDPOINTS.ACCOUNT.PROFILE, {
            preserveScroll: true,
        });
    };

    const handlePasswordSubmit = (e) => {
        e.preventDefault();
        passwordForm.put(API_ENDPOINTS.ACCOUNT.PASSWORD, {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    const handleAddressSubmit = (e) => {
        e.preventDefault();
        addressForm.post(API_ENDPOINTS.ACCOUNT.ADDRESS, {
            preserveScroll: true,
            onSuccess: () => {
                setShowAddressModal(false);
                addressForm.reset();
            },
        });
    };

    const handleDeleteAddress = (id) => {
        if (confirm('Are you sure you want to remove this delivery address?')) {
            router.delete(API_ENDPOINTS.ACCOUNT.ADDRESS_ITEM(id), {
                preserveScroll: true,
            });
        }
    };

    const handleLogout = () => {
        router.post(ROUTES.LOGOUT);
    };

    return (
        <MainLayout>
            <Head title={`My Account Dashboard — ${siteConfig.name}`} />

            <div className="dashboard-layout-container">
                <div className="container">
                    {/* Header Banner */}
                    <div className="dashboard-header-banner">
                        <div className="user-profile-header-left">
                            <div className="user-avatar-disc">
                                {user.name
                                    ? user.name.charAt(0).toUpperCase()
                                    : 'U'}
                            </div>
                            <div className="user-meta-info">
                                <h1>{user.name}</h1>
                                <div className="user-contact-pills">
                                    <span>
                                        <Mail size={14} /> {user.email}
                                    </span>
                                    <span>
                                        <Phone size={14} /> 🇧🇩{' '}
                                        {formatBdPhone(user.phone)}
                                    </span>
                                    <span>
                                        <ShieldCheck
                                            size={14}
                                            className="text-emerald"
                                        />{' '}
                                        Verified Member
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="user-vip-badge">
                            <Award size={16} />
                            <span>
                                TECHCLUB VIP ({stats.tech_points || 0} PTS)
                            </span>
                        </div>
                    </div>

                    {/* 2-Column Grid */}
                    <div className="dashboard-content-grid">
                        {/* Sidebar Navigation */}
                        <aside className="dashboard-sidebar-card">
                            <ul className="dash-nav-menu">
                                <li>
                                    <button
                                        className={`dash-nav-item-btn ${activeTab === 'overview' ? 'active-tab' : ''}`}
                                        onClick={() => setActiveTab('overview')}
                                    >
                                        <div className="dash-nav-left">
                                            <Sparkles size={18} />
                                            <span>Overview</span>
                                        </div>
                                    </button>
                                </li>
                                <li>
                                    <button
                                        className={`dash-nav-item-btn ${activeTab === 'orders' ? 'active-tab' : ''}`}
                                        onClick={() => setActiveTab('orders')}
                                    >
                                        <div className="dash-nav-left">
                                            <Package size={18} />
                                            <span>My Orders</span>
                                        </div>
                                        {orders.length > 0 && (
                                            <span className="dash-nav-badge">
                                                {orders.length}
                                            </span>
                                        )}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        className={`dash-nav-item-btn ${activeTab === 'wishlist' ? 'active-tab' : ''}`}
                                        onClick={() => setActiveTab('wishlist')}
                                    >
                                        <div className="dash-nav-left">
                                            <Heart size={18} />
                                            <span>Wishlist</span>
                                        </div>
                                        {wishlistItems.length > 0 && (
                                            <span className="dash-nav-badge">
                                                {wishlistItems.length}
                                            </span>
                                        )}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        className={`dash-nav-item-btn ${activeTab === 'addresses' ? 'active-tab' : ''}`}
                                        onClick={() =>
                                            setActiveTab('addresses')
                                        }
                                    >
                                        <div className="dash-nav-left">
                                            <MapPin size={18} />
                                            <span>Delivery Addresses</span>
                                        </div>
                                    </button>
                                </li>
                                <li>
                                    <button
                                        className={`dash-nav-item-btn ${activeTab === 'profile' ? 'active-tab' : ''}`}
                                        onClick={() => setActiveTab('profile')}
                                    >
                                        <div className="dash-nav-left">
                                            <User size={18} />
                                            <span>Profile & Security</span>
                                        </div>
                                    </button>
                                </li>

                                {user.role === 'admin' && (
                                    <li className="dash-admin-nav-item">
                                        <Link
                                            href={ROUTES.ADMIN_DASHBOARD}
                                            className="dash-nav-item-btn dash-admin-link"
                                        >
                                            <div className="dash-nav-left">
                                                <ShieldCheck size={18} />
                                                <span>Admin Console</span>
                                            </div>
                                            <ChevronRight size={16} />
                                        </Link>
                                    </li>
                                )}

                                <li className="dash-logout-li">
                                    <button
                                        className="dash-nav-item-btn dash-logout-btn"
                                        onClick={handleLogout}
                                    >
                                        <div className="dash-nav-left">
                                            <LogOut size={18} />
                                            <span>Sign Out</span>
                                        </div>
                                    </button>
                                </li>
                            </ul>
                        </aside>

                        {/* Main Surface */}
                        <main className="dashboard-main-surface">
                            {/* =======================================================
                               TAB 1: OVERVIEW
                               ======================================================= */}
                            {activeTab === 'overview' && (
                                <div>
                                    <div className="dash-tab-header">
                                        <div>
                                            <h2>Account Summary</h2>
                                            <p>
                                                Welcome back, {user.name}! Here
                                                is a summary of your hardware
                                                orders & perks.
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
                                                <strong>
                                                    {stats.total_orders || 0}
                                                </strong>
                                            </div>
                                        </div>

                                        <div className="dash-kpi-card">
                                            <div className="dash-kpi-icon bg-amber">
                                                <Truck size={22} />
                                            </div>
                                            <div className="dash-kpi-info">
                                                <span>PENDING SHIPMENTS</span>
                                                <strong>
                                                    {stats.pending_orders || 0}
                                                </strong>
                                            </div>
                                        </div>

                                        <div className="dash-kpi-card">
                                            <div className="dash-kpi-icon bg-emerald">
                                                <CreditCard size={22} />
                                            </div>
                                            <div className="dash-kpi-info">
                                                <span>TOTAL PURCHASES</span>
                                                <strong>
                                                    {formatBdt(
                                                        stats.total_spent,
                                                    )}
                                                </strong>
                                            </div>
                                        </div>

                                        <div className="dash-kpi-card">
                                            <div className="dash-kpi-icon bg-purple">
                                                <Award size={22} />
                                            </div>
                                            <div className="dash-kpi-info">
                                                <span>TECHCLUB POINTS</span>
                                                <strong>
                                                    {stats.tech_points || 0} pts
                                                </strong>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Recent Orders Snapshot */}
                                    <h3 className="dash-section-title">
                                        Recent Order Activity
                                    </h3>
                                    {orders.length === 0 ? (
                                        <div className="dash-empty-box">
                                            <Package
                                                size={40}
                                                className="dash-empty-icon"
                                            />
                                            <p className="dash-empty-text">
                                                No orders placed yet.
                                            </p>
                                            <Link
                                                href={ROUTES.SHOP}
                                                className="btn btn-primary btn-sm mt-3"
                                            >
                                                Start Shopping
                                            </Link>
                                        </div>
                                    ) : (
                                        <div className="orders-list-wrapper">
                                            {orders.slice(0, 2).map((order) => (
                                                <div
                                                    key={order.id}
                                                    className="order-history-card"
                                                >
                                                    <div className="order-card-header">
                                                        <div className="order-num-tag">
                                                            <span>ORDER </span>
                                                            <strong>
                                                                #
                                                                {
                                                                    order.order_number
                                                                }
                                                            </strong>
                                                        </div>
                                                        <StatusBadge
                                                            status={
                                                                order.status
                                                            }
                                                        />
                                                    </div>
                                                    <div className="order-card-body">
                                                        <div className="order-items-grid">
                                                            {order.items?.map(
                                                                (item) => (
                                                                    <div
                                                                        key={
                                                                            item.id
                                                                        }
                                                                        className="order-item-row"
                                                                    >
                                                                        <ProductImage
                                                                            product={
                                                                                item.product
                                                                            }
                                                                            alt={
                                                                                item.product_name
                                                                            }
                                                                            className="order-item-thumb"
                                                                        />
                                                                        <div className="order-item-details">
                                                                            <div className="order-item-name">
                                                                                {
                                                                                    item.product_name
                                                                                }
                                                                                {item.variant_name
                                                                                    ? ` (${item.variant_name})`
                                                                                    : ''}
                                                                            </div>
                                                                            <div className="order-item-sub">
                                                                                Qty:{' '}
                                                                                {
                                                                                    item.quantity
                                                                                }{' '}
                                                                                ×{' '}
                                                                                {formatBdt(
                                                                                    item.price,
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                        <div className="order-item-price">
                                                                            {formatBdt(
                                                                                item.total,
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="order-card-footer">
                                                        <span className="dash-order-total-label">
                                                            Payment:{' '}
                                                            <strong>
                                                                {
                                                                    order.payment_method
                                                                }
                                                            </strong>{' '}
                                                            (
                                                            {
                                                                order.payment_status
                                                            }
                                                            )
                                                        </span>
                                                        <button
                                                            className="btn btn-outline btn-sm"
                                                            onClick={() => {
                                                                setSelectedOrder(
                                                                    order,
                                                                );
                                                                setActiveTab(
                                                                    'orders',
                                                                );
                                                            }}
                                                        >
                                                            View Full Details
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* =======================================================
                               TAB 2: ORDERS LIST
                               ======================================================= */}
                            {activeTab === 'orders' && (
                                <div>
                                    <div className="dash-tab-header">
                                        <div>
                                            <h2>My Hardware Orders</h2>
                                            <p>
                                                Track your active shipments,
                                                delivery status, and invoices.
                                            </p>
                                        </div>
                                    </div>

                                    {orders.length === 0 ? (
                                        <div className="dash-empty-box">
                                            <p className="dash-empty-text">
                                                You haven't placed any orders
                                                yet.
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
                                            {orders.map((order) => (
                                                <div
                                                    key={order.id}
                                                    className="order-history-card"
                                                >
                                                    <div className="order-card-header">
                                                        <div>
                                                            <span className="dash-order-num-label">
                                                                Order
                                                                Number:{' '}
                                                            </span>
                                                            <strong className="dash-order-num-strong">
                                                                #
                                                                {
                                                                    order.order_number
                                                                }
                                                            </strong>
                                                            <span className="dash-order-divider">
                                                                |
                                                            </span>
                                                            <span className="dash-order-date">
                                                                {formatDate(
                                                                    order.created_at,
                                                                )}
                                                            </span>
                                                        </div>
                                                        <StatusBadge
                                                            status={
                                                                order.status
                                                            }
                                                        />
                                                    </div>

                                                    <div className="order-card-body">
                                                        <div className="order-items-grid">
                                                            {order.items?.map(
                                                                (item) => (
                                                                    <div
                                                                        key={
                                                                            item.id
                                                                        }
                                                                        className="order-item-row"
                                                                    >
                                                                        <ProductImage
                                                                            product={
                                                                                item.product
                                                                            }
                                                                            alt={
                                                                                item.product_name
                                                                            }
                                                                            className="order-item-thumb"
                                                                        />
                                                                        <div className="order-item-details">
                                                                            <div className="order-item-name">
                                                                                {
                                                                                    item.product_name
                                                                                }
                                                                                {item.variant_name
                                                                                    ? ` (${item.variant_name})`
                                                                                    : ''}
                                                                            </div>
                                                                            <div className="order-item-sub">
                                                                                Qty:{' '}
                                                                                {
                                                                                    item.quantity
                                                                                }{' '}
                                                                                ×{' '}
                                                                                {formatBdt(
                                                                                    item.price,
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                        <div className="order-item-price">
                                                                            {formatBdt(
                                                                                item.total,
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    </div>

                                                    <div className="order-card-footer">
                                                        <div>
                                                            <span className="dash-order-total-label">
                                                                Total:{' '}
                                                            </span>
                                                            <strong className="dash-order-total-val">
                                                                {formatBdt(
                                                                    order.total,
                                                                )}
                                                            </strong>
                                                        </div>
                                                        <div className="dash-order-actions-row">
                                                            <button
                                                                className="btn btn-outline btn-sm"
                                                                onClick={() =>
                                                                    setSelectedOrder(
                                                                        order,
                                                                    )
                                                                }
                                                            >
                                                                <Eye
                                                                    size={14}
                                                                />{' '}
                                                                Order Invoice
                                                            </button>
                                                            {isCancellable(
                                                                order,
                                                            ) && (
                                                                <button
                                                                    className="btn btn-outline btn-sm dash-order-cancel-btn"
                                                                    disabled={
                                                                        cancellingId ===
                                                                        order.id
                                                                    }
                                                                    onClick={() =>
                                                                        cancelOrder(
                                                                            order,
                                                                        )
                                                                    }
                                                                >
                                                                    <XCircle
                                                                        size={
                                                                            14
                                                                        }
                                                                    />{' '}
                                                                    {cancellingId ===
                                                                    order.id
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
                                </div>
                            )}

                            {/* =======================================================
                               TAB 3: WISHLIST
                               ======================================================= */}
                            {activeTab === 'wishlist' && (
                                <div>
                                    <div className="dash-tab-header">
                                        <div>
                                            <h2>Saved Wishlist</h2>
                                            <p>
                                                Keep track of products you plan
                                                to purchase or include in your
                                                custom PC build.
                                            </p>
                                        </div>
                                    </div>

                                    {wishlistItems.length === 0 ? (
                                        <div className="dash-empty-box">
                                            <Heart
                                                size={44}
                                                className="dash-empty-icon"
                                            />
                                            <h4 className="dash-empty-text">
                                                Your wishlist is currently empty
                                            </h4>
                                            <p className="dash-empty-text">
                                                Browse our catalog and click the
                                                heart icon on any product to
                                                save it here.
                                            </p>
                                            <Link
                                                href={ROUTES.SHOP}
                                                className="btn btn-primary btn-sm mt-3"
                                            >
                                                Explore Products
                                            </Link>
                                        </div>
                                    ) : (
                                        <div className="standard-products-grid">
                                            {wishlistItems.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="standard-product-card"
                                                >
                                                    <Link
                                                        href={`/products/${item.product?.slug}`}
                                                        className="card-image-wrapper"
                                                    >
                                                        <ProductImage
                                                            product={
                                                                item.product
                                                            }
                                                            alt={
                                                                item.product
                                                                    ?.name
                                                            }
                                                        />
                                                    </Link>
                                                    <div className="card-content-body">
                                                        <span className="card-brand-tag">
                                                            {
                                                                item.product
                                                                    ?.brand
                                                                    ?.name
                                                            }
                                                        </span>
                                                        <Link
                                                            href={`/products/${item.product?.slug}`}
                                                            className="card-product-title truncate-2"
                                                        >
                                                            {item.product?.name}
                                                        </Link>
                                                        <div className="card-pricing-row">
                                                            <span className="current-price-tag">
                                                                {formatBdt(
                                                                    item.product
                                                                        ?.price,
                                                                )}
                                                            </span>
                                                        </div>
                                                        <Link
                                                            href={`/products/${item.product?.slug}`}
                                                            className="btn btn-primary btn-sm w-100 mt-2"
                                                        >
                                                            View Product
                                                        </Link>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* =======================================================
                               TAB 4: DELIVERY ADDRESSES
                               ======================================================= */}
                            {activeTab === 'addresses' && (
                                <div>
                                    <div className="dash-tab-header">
                                        <div>
                                            <h2>
                                                Delivery Addresses (Bangladesh)
                                            </h2>
                                            <p>
                                                Manage your nationwide delivery
                                                addresses for seamless 1-click
                                                checkout.
                                            </p>
                                        </div>
                                        <button
                                            className="btn btn-primary btn-sm"
                                            onClick={() => {
                                                addressForm.reset();
                                                setShowAddressModal(true);
                                            }}
                                        >
                                            <Plus size={15} /> Add New Address
                                        </button>
                                    </div>

                                    {addresses.length === 0 ? (
                                        <div className="dash-empty-box">
                                            <MapPin
                                                size={40}
                                                className="dash-empty-icon"
                                            />
                                            <p className="dash-empty-text">
                                                No saved addresses yet.
                                            </p>
                                            <button
                                                className="btn btn-outline btn-sm mt-3"
                                                onClick={() =>
                                                    setShowAddressModal(true)
                                                }
                                            >
                                                Add Your First Address
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="addresses-grid">
                                            {addresses.map((addr) => (
                                                <div
                                                    key={addr.id}
                                                    className={`address-item-card ${addr.is_default ? 'is-default-address' : ''}`}
                                                >
                                                    {addr.is_default && (
                                                        <span className="default-addr-badge">
                                                            DEFAULT ADDRESS
                                                        </span>
                                                    )}
                                                    <h4>
                                                        {addr.city},{' '}
                                                        {addr.district}
                                                    </h4>
                                                    <p>{addr.address}</p>
                                                    <span className="dash-address-meta">
                                                        Division:{' '}
                                                        <strong>
                                                            {addr.division}
                                                        </strong>
                                                    </span>
                                                    <div className="dash-address-actions">
                                                        <button
                                                            className="btn btn-outline btn-sm dash-address-remove-btn"
                                                            onClick={() =>
                                                                handleDeleteAddress(
                                                                    addr.id,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 size={13} />{' '}
                                                            Remove
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* =======================================================
                               TAB 5: PROFILE & SECURITY SETTINGS
                               ======================================================= */}
                            {activeTab === 'profile' && (
                                <div>
                                    <div className="dash-tab-header">
                                        <div>
                                            <h2>Profile & Security</h2>
                                            <p>
                                                Update your personal
                                                information, Bangladeshi mobile
                                                number, and password.
                                            </p>
                                        </div>
                                    </div>

                                    {/* Personal Info Form */}
                                    <form
                                        onSubmit={handleProfileSubmit}
                                        className="dash-profile-form"
                                    >
                                        <h3 className="dash-profile-form-title">
                                            Personal Details
                                        </h3>

                                        <div className="auth-form-group">
                                            <label className="auth-label">
                                                Full Name
                                            </label>
                                            <input
                                                type="text"
                                                value={profileForm.data.name}
                                                onChange={(e) =>
                                                    profileForm.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                                className={`auth-input ${profileForm.errors.name ? 'has-error' : ''}`}
                                            />
                                            {profileForm.errors.name && (
                                                <span className="auth-error-msg">
                                                    {profileForm.errors.name}
                                                </span>
                                            )}
                                        </div>

                                        <div className="auth-form-group">
                                            <label className="auth-label">
                                                Email Address
                                            </label>
                                            <input
                                                type="email"
                                                value={profileForm.data.email}
                                                onChange={(e) =>
                                                    profileForm.setData(
                                                        'email',
                                                        e.target.value,
                                                    )
                                                }
                                                className={`auth-input ${profileForm.errors.email ? 'has-error' : ''}`}
                                            />
                                            {profileForm.errors.email && (
                                                <span className="auth-error-msg">
                                                    {profileForm.errors.email}
                                                </span>
                                            )}
                                        </div>

                                        <div className="auth-form-group">
                                            <label className="auth-label">
                                                Bangladeshi Mobile Number
                                            </label>
                                            <div className="auth-input-wrapper">
                                                <span className="bd-phone-prefix-pill">
                                                    🇧🇩 +88
                                                </span>
                                                <input
                                                    type="tel"
                                                    value={
                                                        profileForm.data.phone
                                                    }
                                                    onChange={(e) =>
                                                        profileForm.setData(
                                                            'phone',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className={`auth-input with-bd-prefix ${profileForm.errors.phone ? 'has-error' : ''}`}
                                                />
                                            </div>
                                            {profileForm.errors.phone && (
                                                <span className="auth-error-msg">
                                                    {profileForm.errors.phone}
                                                </span>
                                            )}
                                        </div>

                                        <button
                                            type="submit"
                                            className="btn btn-primary"
                                            disabled={profileForm.processing}
                                        >
                                            {profileForm.processing
                                                ? 'Saving...'
                                                : 'Save Profile Changes'}
                                        </button>
                                    </form>

                                    {/* Password Change Form */}
                                    <form
                                        onSubmit={handlePasswordSubmit}
                                        className="dash-password-form"
                                    >
                                        <h3 className="dash-profile-form-title">
                                            Change Account Password
                                        </h3>

                                        <div className="auth-form-group">
                                            <label className="auth-label">
                                                Current Password
                                            </label>
                                            <input
                                                type="password"
                                                value={
                                                    passwordForm.data
                                                        .current_password
                                                }
                                                onChange={(e) =>
                                                    passwordForm.setData(
                                                        'current_password',
                                                        e.target.value,
                                                    )
                                                }
                                                className={`auth-input ${passwordForm.errors.current_password ? 'has-error' : ''}`}
                                            />
                                            {passwordForm.errors
                                                .current_password && (
                                                <span className="auth-error-msg">
                                                    {
                                                        passwordForm.errors
                                                            .current_password
                                                    }
                                                </span>
                                            )}
                                        </div>

                                        <div className="auth-form-group">
                                            <label className="auth-label">
                                                New Password
                                            </label>
                                            <input
                                                type="password"
                                                value={
                                                    passwordForm.data.password
                                                }
                                                onChange={(e) =>
                                                    passwordForm.setData(
                                                        'password',
                                                        e.target.value,
                                                    )
                                                }
                                                className={`auth-input ${passwordForm.errors.password ? 'has-error' : ''}`}
                                            />
                                            {passwordForm.errors.password && (
                                                <span className="auth-error-msg">
                                                    {
                                                        passwordForm.errors
                                                            .password
                                                    }
                                                </span>
                                            )}
                                        </div>

                                        <div className="auth-form-group">
                                            <label className="auth-label">
                                                Confirm New Password
                                            </label>
                                            <input
                                                type="password"
                                                value={
                                                    passwordForm.data
                                                        .password_confirmation
                                                }
                                                onChange={(e) =>
                                                    passwordForm.setData(
                                                        'password_confirmation',
                                                        e.target.value,
                                                    )
                                                }
                                                className={`auth-input ${passwordForm.errors.password_confirmation ? 'has-error' : ''}`}
                                            />
                                            {passwordForm.errors
                                                .password_confirmation && (
                                                <span className="auth-error-msg">
                                                    {
                                                        passwordForm.errors
                                                            .password_confirmation
                                                    }
                                                </span>
                                            )}
                                        </div>

                                        <button
                                            type="submit"
                                            className="btn btn-primary"
                                            disabled={passwordForm.processing}
                                        >
                                            {passwordForm.processing
                                                ? 'Updating...'
                                                : 'Update Password'}
                                        </button>
                                    </form>
                                </div>
                            )}
                        </main>
                    </div>
                </div>
            </div>

            {/* Address Modal (Reusable Modal Component) */}
            <Modal
                isOpen={showAddressModal}
                onClose={() => setShowAddressModal(false)}
                title={
                    addressForm.data.id
                        ? 'Edit Delivery Address'
                        : 'Add Delivery Address'
                }
                maxWidth="480px"
            >
                <form onSubmit={handleAddressSubmit}>
                    <div className="auth-form-group">
                        <label className="auth-label">Division</label>
                        <select
                            value={addressForm.data.division}
                            onChange={(e) =>
                                addressForm.setData('division', e.target.value)
                            }
                            className="auth-input dash-input-pad"
                        >
                            <option value="Dhaka">Dhaka</option>
                            <option value="Chattogram">Chattogram</option>
                            <option value="Rajshahi">Rajshahi</option>
                            <option value="Khulna">Khulna</option>
                            <option value="Sylhet">Sylhet</option>
                            <option value="Barishal">Barishal</option>
                            <option value="Rangpur">Rangpur</option>
                            <option value="Mymensingh">Mymensingh</option>
                        </select>
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">District</label>
                        <input
                            type="text"
                            value={addressForm.data.district}
                            onChange={(e) =>
                                addressForm.setData('district', e.target.value)
                            }
                            placeholder="e.g. Dhaka"
                            className="auth-input dash-input-pad"
                        />
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">
                            City / Thana / Area
                        </label>
                        <input
                            type="text"
                            value={addressForm.data.city}
                            onChange={(e) =>
                                addressForm.setData('city', e.target.value)
                            }
                            placeholder="e.g. Dhanmondi, Gulshan, Agrabad"
                            className="auth-input dash-input-pad"
                        />
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">
                            Full Street Address
                        </label>
                        <textarea
                            value={addressForm.data.address}
                            onChange={(e) =>
                                addressForm.setData('address', e.target.value)
                            }
                            placeholder="House, Road, Block, Flat Number"
                            className="auth-input dash-textarea-custom"
                        />
                    </div>

                    <div className="dash-modal-btn-row">
                        <button
                            type="button"
                            className="btn btn-outline"
                            onClick={() => setShowAddressModal(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={addressForm.processing}
                        >
                            Save Address
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Order Invoice Modal (Reusable Modal Component) */}
            <Modal
                isOpen={!!selectedOrder}
                onClose={() => setSelectedOrder(null)}
                title={`Order Invoice #${selectedOrder?.order_number || ''}`}
                maxWidth="560px"
                footer={
                    <button
                        className="btn btn-primary"
                        onClick={() => setSelectedOrder(null)}
                    >
                        Close Invoice
                    </button>
                }
            >
                {selectedOrder && (
                    <div>
                        {/* The account has promised "invoices" all along
                            without producing one. */}
                        <a
                            href={`/orders/${selectedOrder.id}/invoice`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="dash-invoice-link"
                        >
                            <Printer size={15} /> Print invoice
                        </a>

                        <div className="dash-modal-invoice-header">
                            <div>
                                <span className="dash-modal-invoice-date">
                                    Placed on{' '}
                                    {formatDate(selectedOrder.created_at)}
                                </span>
                            </div>
                            <StatusBadge status={selectedOrder.status} />
                        </div>

                        <h4 className="dash-modal-items-heading">
                            Items Summary
                        </h4>
                        <div className="dash-modal-items-list">
                            {selectedOrder.items?.map((item) => (
                                <div
                                    key={item.id}
                                    className="dash-modal-item-row"
                                >
                                    <div className="dash-modal-item-info">
                                        <strong>{item.product_name}</strong>
                                        <div className="dash-modal-item-meta">
                                            Qty: {item.quantity} ×{' '}
                                            {formatBdt(item.price)}
                                        </div>
                                    </div>
                                    <strong className="dash-modal-item-total">
                                        {formatBdt(item.total)}
                                    </strong>
                                </div>
                            ))}
                        </div>

                        <div className="dash-modal-summary-box">
                            <div className="dash-modal-summary-row">
                                <span>Subtotal:</span>
                                <span>{formatBdt(selectedOrder.subtotal)}</span>
                            </div>
                            <div className="dash-modal-summary-row">
                                <span>Shipping:</span>
                                <span>
                                    {selectedOrder.shipping_fee > 0
                                        ? formatBdt(selectedOrder.shipping_fee)
                                        : 'FREE'}
                                </span>
                            </div>
                            <div className="dash-modal-summary-total">
                                <span>Total Amount:</span>
                                <span>{formatBdt(selectedOrder.total)}</span>
                            </div>
                        </div>
                    </div>
                )}
            </Modal>
        </MainLayout>
    );
}
