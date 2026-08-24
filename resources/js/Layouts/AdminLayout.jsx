import React from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import ToastContainer from '../Components/Toast';
import {
    LayoutDashboard,
    Package,
    Boxes,
    Truck,
    ShoppingCart,
    FolderTree,
    Users,
    ExternalLink,
    LogOut,
    Image,
    Tag,
    MapPin,
    Sliders,
    BookOpen,
    ShieldCheck,
    MessageSquare,
    Menu,
    X,
} from 'lucide-react';
import { BrandLogo } from '../Components/BrandLogo';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';
import './AdminLayout.css';

export default function AdminLayout({
    children,
    title = 'Executive Dashboard',
    subtitle = `${siteConfig.name} Operations & Inventory`,
}) {
    // The sidebar is a drawer below the desktop breakpoint. Without this it
    // took 260px of a 375px screen and left the page an unusable sliver.
    const [navOpen, setNavOpen] = React.useState(false);

    const { auth } = usePage().props;
    const user = auth.user || {};
    const currentUrl =
        typeof window !== 'undefined' ? window.location.pathname : '';

    // Close it after navigating, or the drawer stays over the page just
    // arrived at. Declared after currentUrl: a dependency array is evaluated
    // during render, so referencing it earlier is a temporal dead zone error.
    React.useEffect(() => {
        setNavOpen(false);
    }, [currentUrl]);

    const handleLogout = () => {
        router.post(ROUTES.LOGOUT);
    };

    return (
        <div className={`admin-wrapper ${navOpen ? 'nav-open' : ''}`}>
            {/* Tapping away closes the drawer, which is what people expect. */}
            <div
                className="admin-nav-backdrop"
                onClick={() => setNavOpen(false)}
                aria-hidden="true"
            />

            {/* Sidebar */}
            <aside className="admin-sidebar">
                <div className="admin-sidebar-header">
                    <BrandLogo variant="admin" href={ROUTES.ADMIN_DASHBOARD} />
                </div>

                <div className="admin-sidebar-nav">
                    <div className="admin-nav-section-label">Management</div>

                    <Link
                        href={ROUTES.ADMIN_DASHBOARD}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/dashboard') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <LayoutDashboard size={17} />
                            <span>Overview</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_ORDERS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/orders') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <ShoppingCart size={17} />
                            <span>Orders & Shipping</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_PRODUCTS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/products') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Package size={17} />
                            <span>Products & Stock</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_STOCK}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/stock') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Boxes size={17} />
                            <span>Stock & Inventory</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_SUPPLIERS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/suppliers') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Truck size={17} />
                            <span>Suppliers</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_CATEGORIES}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/categories') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <FolderTree size={17} />
                            <span>Category Tree</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_CUSTOMERS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/customers') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Users size={17} />
                            <span>Customers Directory</span>
                        </div>
                    </Link>

                    <div className="admin-nav-section-label admin-nav-section-spacer">
                        Marketing &amp; Media
                    </div>

                    <Link
                        href={ROUTES.ADMIN_BANNERS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/banners') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Image size={17} />
                            <span>Banners &amp; Sliders</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_COUPONS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/coupons') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Tag size={17} />
                            <span>Promo Coupons</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_BLOGS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/blogs') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <BookOpen size={17} />
                            <span>Tech Journal</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_STORES}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/stores') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <MapPin size={17} />
                            <span>Showrooms</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_REVIEWS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/reviews') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <MessageSquare size={17} />
                            <span>Customer Reviews</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_WARRANTY}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/warranty') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <ShieldCheck size={17} />
                            <span>Warranty &amp; RMA</span>
                        </div>
                    </Link>

                    <Link
                        href={ROUTES.ADMIN_SETTINGS}
                        className={`admin-nav-link ${currentUrl.startsWith('/admin/settings') ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <Sliders size={17} />
                            <span>Site Settings</span>
                        </div>
                    </Link>

                    <div className="admin-nav-section-label admin-nav-section-spacer">
                        Storefront
                    </div>

                    <Link
                        href={ROUTES.HOME}
                        className="admin-nav-link"
                        target="_blank"
                    >
                        <div className="admin-nav-left">
                            <ExternalLink size={17} />
                            <span>View Live Shop</span>
                        </div>
                    </Link>
                </div>

                {/* Sidebar Footer User Card */}
                <div className="admin-sidebar-footer">
                    <div className="admin-user-card">
                        <div className="admin-user-avatar">
                            {user.name
                                ? user.name.charAt(0).toUpperCase()
                                : 'A'}
                        </div>
                        <div className="admin-user-info">
                            <div className="admin-user-name">{user.name}</div>
                            <div className="admin-user-role">Super Admin</div>
                        </div>
                        <button
                            type="button"
                            onClick={handleLogout}
                            title="Sign Out"
                            className="admin-logout-btn"
                        >
                            <LogOut size={16} />
                        </button>
                    </div>
                </div>
            </aside>

            {/* Main Viewport */}
            <div className="admin-main-viewport">
                {/* Topbar */}
                <header className="admin-topbar">
                    <button
                        type="button"
                        className="admin-nav-toggle"
                        aria-label={navOpen ? 'Close menu' : 'Open menu'}
                        aria-expanded={navOpen}
                        onClick={() => setNavOpen((open) => !open)}
                    >
                        {navOpen ? <X size={20} /> : <Menu size={20} />}
                    </button>

                    <div className="admin-page-title-group">
                        <h2>{title}</h2>
                        <p>{subtitle}</p>
                    </div>

                    <div className="admin-topbar-actions">
                        <Link
                            href={ROUTES.HOME}
                            className="admin-live-store-btn"
                            target="_blank"
                        >
                            <span>Open Store</span>
                            <ExternalLink size={14} />
                        </Link>
                    </div>
                </header>

                {/* Content Body */}
                <div className="admin-content-body">{children}</div>
            </div>

            {/*
                Toasts render here as well as in MainLayout. Every admin screen
                calls toast.success/error, but nothing in this layout rendered
                them, so admin feedback was pushed into the store and never seen.
            */}
            <ToastContainer />
        </div>
    );
}
