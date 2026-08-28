import React from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import ToastContainer from '../Components/Toast';
import { useFlashToasts } from '../hooks';
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
    Wallet,
    LineChart,
    Tags,
    Undo2,
    UserCog,
    Inbox,
    AtSign,
    FileText,
    ClipboardList,
    SlidersHorizontal,
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
    // Show whatever the server flashed on the last write.
    useFlashToasts();

    // The sidebar is a drawer below the desktop breakpoint. Without this it
    // took 260px of a 375px screen and left the page an unusable sliver.
    const [navOpen, setNavOpen] = React.useState(false);

    const { auth } = usePage().props;
    const user = auth.user || {};

    /*
     * The nav is drawn from what this account may actually do. Showing a link
     * that redirects straight back to the dashboard teaches people to distrust
     * the menu.
     */
    const abilities = user.abilities || [];
    const can = (ability) => abilities.includes(ability);
    const currentUrl =
        typeof window !== 'undefined' ? window.location.pathname : '';

    /*
     * Only the deepest link lights up.
     *
     * Every link tested currentUrl.startsWith(its own path), so on
     * /admin/stock/count both "Stock & Inventory" and "Stock Take" were
     * highlighted — the sidebar claimed to be in two places at once. Somebody
     * had already hit this with expenses and patched that one link with a
     * !startsWith of the other; the next nested route would have needed the
     * same patch again.
     *
     * The admin paths come from ROUTES rather than a list kept here, so a link
     * added later is covered without anyone remembering to register it.
     */
    const activePath = React.useMemo(() => {
        const paths = Object.values(ROUTES)
            .filter((v) => typeof v === 'string' && v.startsWith('/admin'))
            .sort((a, b) => b.length - a.length);

        return (
            paths.find(
                (path) =>
                    currentUrl === path || currentUrl.startsWith(path + '/'),
            ) ?? null
        );
    }, [currentUrl]);

    const isActive = (path) => activePath === path;

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
                        className={`admin-nav-link ${isActive(ROUTES.ADMIN_DASHBOARD) ? 'active' : ''}`}
                    >
                        <div className="admin-nav-left">
                            <LayoutDashboard size={17} />
                            <span>Overview</span>
                        </div>
                    </Link>

                    {can('orders') && (
                        <Link
                            href={ROUTES.ADMIN_ORDERS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_ORDERS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <ShoppingCart size={17} />
                                <span>Orders & Shipping</span>
                            </div>
                        </Link>
                    )}

                    {can('catalogue') && (
                        <Link
                            href={ROUTES.ADMIN_PRODUCTS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_PRODUCTS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Package size={17} />
                                <span>Products & Stock</span>
                            </div>
                        </Link>
                    )}

                    {can('stock') && (
                        <Link
                            href={ROUTES.ADMIN_STOCK}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_STOCK) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Boxes size={17} />
                                <span>Stock & Inventory</span>
                            </div>
                        </Link>
                    )}

                    {can('stock') && (
                        <Link
                            href={ROUTES.ADMIN_STOCK_COUNT}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_STOCK_COUNT) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <ClipboardList size={17} />
                                <span>Stock Take</span>
                            </div>
                        </Link>
                    )}

                    {can('stock') && (
                        <Link
                            href={ROUTES.ADMIN_STOCK_ADJUSTMENTS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_STOCK_ADJUSTMENTS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <SlidersHorizontal size={17} />
                                <span>Adjustments</span>
                            </div>
                        </Link>
                    )}

                    {can('stock') && (
                        <Link
                            href={ROUTES.ADMIN_SUPPLIERS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_SUPPLIERS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Truck size={17} />
                                <span>Suppliers</span>
                            </div>
                        </Link>
                    )}

                    {can('catalogue') && (
                        <Link
                            href={ROUTES.ADMIN_CATEGORIES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_CATEGORIES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <FolderTree size={17} />
                                <span>Category Tree</span>
                            </div>
                        </Link>
                    )}

                    {can('customers') && (
                        <Link
                            href={ROUTES.ADMIN_CUSTOMERS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_CUSTOMERS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Users size={17} />
                                <span>Customers Directory</span>
                            </div>
                        </Link>
                    )}

                    {can('couriers') && (
                        <Link
                            href={ROUTES.ADMIN_COURIERS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_COURIERS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Truck size={17} />
                                <span>Couriers</span>
                            </div>
                        </Link>
                    )}

                    {(can('refunds') || can('finance')) && (
                        <div className="admin-nav-section-label admin-nav-section-spacer">
                            Money
                        </div>
                    )}

                    {can('refunds') && (
                        <Link
                            href={ROUTES.ADMIN_REFUNDS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_REFUNDS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Undo2 size={17} />
                                <span>Refunds</span>
                            </div>
                        </Link>
                    )}

                    {can('finance') && (
                        <Link
                            href={ROUTES.ADMIN_EXPENSES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_EXPENSES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Wallet size={17} />
                                <span>Expenses</span>
                            </div>
                        </Link>
                    )}

                    {can('finance') && (
                        <Link
                            href={ROUTES.ADMIN_EXPENSE_CATEGORIES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_EXPENSE_CATEGORIES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Tags size={17} />
                                <span>Expense Categories</span>
                            </div>
                        </Link>
                    )}

                    {can('finance') && (
                        <Link
                            href={ROUTES.ADMIN_REPORTS_PROFIT}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_REPORTS_PROFIT) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <LineChart size={17} />
                                <span>Profit &amp; Loss</span>
                            </div>
                        </Link>
                    )}

                    {(can('marketing') ||
                        can('support') ||
                        can('settings')) && (
                        <div className="admin-nav-section-label admin-nav-section-spacer">
                            Marketing &amp; Media
                        </div>
                    )}

                    {can('marketing') && (
                        <Link
                            href={ROUTES.ADMIN_BANNERS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_BANNERS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Image size={17} />
                                <span>Banners &amp; Sliders</span>
                            </div>
                        </Link>
                    )}

                    {can('marketing') && (
                        <Link
                            href={ROUTES.ADMIN_COUPONS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_COUPONS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Tag size={17} />
                                <span>Promo Coupons</span>
                            </div>
                        </Link>
                    )}

                    {can('marketing') && (
                        <Link
                            href={ROUTES.ADMIN_BLOGS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_BLOGS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <BookOpen size={17} />
                                <span>Tech Journal</span>
                            </div>
                        </Link>
                    )}

                    {can('settings') && (
                        <Link
                            href={ROUTES.ADMIN_STORES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_STORES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <MapPin size={17} />
                                <span>Showrooms</span>
                            </div>
                        </Link>
                    )}

                    {can('support') && (
                        <Link
                            href={ROUTES.ADMIN_REVIEWS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_REVIEWS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <MessageSquare size={17} />
                                <span>Customer Reviews</span>
                            </div>
                        </Link>
                    )}

                    {can('support') && (
                        <Link
                            href={ROUTES.ADMIN_WARRANTY}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_WARRANTY) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <ShieldCheck size={17} />
                                <span>Warranty &amp; RMA</span>
                            </div>
                        </Link>
                    )}

                    {can('support') && (
                        <Link
                            href={ROUTES.ADMIN_MESSAGES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_MESSAGES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Inbox size={17} />
                                <span>Messages</span>
                            </div>
                        </Link>
                    )}

                    {can('marketing') && (
                        <Link
                            href={ROUTES.ADMIN_PAGES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_PAGES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <FileText size={17} />
                                <span>Pages</span>
                            </div>
                        </Link>
                    )}

                    {can('marketing') && (
                        <Link
                            href={ROUTES.ADMIN_SUBSCRIBERS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_SUBSCRIBERS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <AtSign size={17} />
                                <span>Subscribers</span>
                            </div>
                        </Link>
                    )}

                    {can('staff') && (
                        <Link
                            href={ROUTES.ADMIN_ROLES}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_ROLES) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <ShieldCheck size={17} />
                                <span>Roles</span>
                            </div>
                        </Link>
                    )}

                    {can('staff') && (
                        <Link
                            href={ROUTES.ADMIN_STAFF}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_STAFF) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <UserCog size={17} />
                                <span>Staff & Roles</span>
                            </div>
                        </Link>
                    )}

                    {can('settings') && (
                        <Link
                            href={ROUTES.ADMIN_SETTINGS}
                            className={`admin-nav-link ${isActive(ROUTES.ADMIN_SETTINGS) ? 'active' : ''}`}
                        >
                            <div className="admin-nav-left">
                                <Sliders size={17} />
                                <span>Site Settings</span>
                            </div>
                        </Link>
                    )}

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
                            <div className="admin-user-role">
                                {user.role_label || 'Staff'}
                            </div>
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
