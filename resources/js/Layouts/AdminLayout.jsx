import React from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import ToastContainer from '../Components/Toast';
import { useFlashToasts } from '../hooks';
import {
    LayoutDashboard,
    Package,
    Boxes,
    Truck,
    Factory,
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
    KeyRound,
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
    Hash,
} from 'lucide-react';
import { BrandLogo } from '../Components/BrandLogo';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';
import './AdminLayout.css';

/**
 * The sidebar, as a list rather than as markup.
 *
 * It was 300 lines of near-identical <Link> blocks under two headings, and the
 * headings had stopped describing what was under them: "Management" held
 * everything from the dashboard to couriers, and "Marketing & Media" had
 * collected Roles, Staff and Site Settings — nobody looking for permissions
 * would think to look under marketing.
 *
 * Written this way, moving a link between groups is moving one line, and a
 * group's heading appears exactly when something inside it does. That last part
 * used to be a hand-kept condition — `can('refunds') || can('finance')` — which
 * a new link with a new ability would silently fail to include, leaving its
 * heading hidden above a visible link.
 *
 * `ability` null means everyone who can reach the admin at all sees it.
 */
const NAV_GROUPS = [
    {
        // The dashboard needs no heading; it is the first thing and it is one
        // thing.
        label: null,
        items: [
            {
                label: 'Overview',
                href: ROUTES.ADMIN_DASHBOARD,
                icon: LayoutDashboard,
                ability: null,
            },
        ],
    },
    {
        label: 'Orders',
        items: [
            {
                label: 'Orders & Shipping',
                href: ROUTES.ADMIN_ORDERS,
                icon: ShoppingCart,
                ability: 'orders',
            },
            {
                label: 'Customers',
                href: ROUTES.ADMIN_CUSTOMERS,
                icon: Users,
                ability: 'customers',
            },
            {
                label: 'Couriers',
                href: ROUTES.ADMIN_COURIERS,
                icon: Truck,
                ability: 'couriers',
            },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            {
                // Was "Products & Stock", which pointed at neither clearly now
                // that stock has five screens of its own below.
                label: 'Products',
                href: ROUTES.ADMIN_PRODUCTS,
                icon: Package,
                ability: 'catalogue',
            },
            {
                label: 'Category Tree',
                href: ROUTES.ADMIN_CATEGORIES,
                icon: FolderTree,
                ability: 'catalogue',
            },
        ],
    },
    {
        label: 'Stock',
        items: [
            {
                label: 'Stock & Inventory',
                href: ROUTES.ADMIN_STOCK,
                icon: Boxes,
                ability: 'stock',
            },
            {
                label: 'Stock Take',
                href: ROUTES.ADMIN_STOCK_COUNT,
                icon: ClipboardList,
                ability: 'stock',
            },
            {
                label: 'Adjustments',
                href: ROUTES.ADMIN_STOCK_ADJUSTMENTS,
                icon: SlidersHorizontal,
                ability: 'stock',
            },
            {
                label: 'Serial Numbers',
                href: ROUTES.ADMIN_STOCK_SERIALS,
                icon: Hash,
                ability: 'stock',
            },
            {
                // Factory rather than Truck: couriers already own the lorry,
                // and two links with the same icon read as the same thing.
                label: 'Suppliers',
                href: ROUTES.ADMIN_SUPPLIERS,
                icon: Factory,
                ability: 'stock',
            },
        ],
    },
    {
        label: 'Money',
        items: [
            {
                label: 'Refunds',
                href: ROUTES.ADMIN_REFUNDS,
                icon: Undo2,
                ability: 'refunds',
            },
            {
                label: 'Expenses',
                href: ROUTES.ADMIN_EXPENSES,
                icon: Wallet,
                ability: 'finance',
            },
            {
                label: 'Expense Categories',
                href: ROUTES.ADMIN_EXPENSE_CATEGORIES,
                icon: Tags,
                ability: 'finance',
            },
            {
                label: 'Profit & Loss',
                href: ROUTES.ADMIN_REPORTS_PROFIT,
                icon: LineChart,
                ability: 'finance',
            },
        ],
    },
    {
        label: 'Marketing',
        items: [
            {
                label: 'Banners & Sliders',
                href: ROUTES.ADMIN_BANNERS,
                icon: Image,
                ability: 'marketing',
            },
            {
                label: 'Promo Coupons',
                href: ROUTES.ADMIN_COUPONS,
                icon: Tag,
                ability: 'marketing',
            },
            {
                label: 'Tech Journal',
                href: ROUTES.ADMIN_BLOGS,
                icon: BookOpen,
                ability: 'marketing',
            },
            {
                label: 'Pages',
                href: ROUTES.ADMIN_PAGES,
                icon: FileText,
                ability: 'marketing',
            },
            {
                label: 'Subscribers',
                href: ROUTES.ADMIN_SUBSCRIBERS,
                icon: AtSign,
                ability: 'marketing',
            },
        ],
    },
    {
        label: 'Support',
        items: [
            {
                label: 'Messages',
                href: ROUTES.ADMIN_MESSAGES,
                icon: Inbox,
                ability: 'support',
            },
            {
                label: 'Customer Reviews',
                href: ROUTES.ADMIN_REVIEWS,
                icon: MessageSquare,
                ability: 'support',
            },
            {
                label: 'Warranty & RMA',
                href: ROUTES.ADMIN_WARRANTY,
                icon: ShieldCheck,
                ability: 'support',
            },
        ],
    },
    {
        label: 'Setup',
        items: [
            {
                label: 'Showrooms',
                href: ROUTES.ADMIN_STORES,
                icon: MapPin,
                ability: 'settings',
            },
            {
                // Was "Staff & Roles", directly above a separate link called
                // "Roles" — two names for what looked like one screen.
                label: 'Staff',
                href: ROUTES.ADMIN_STAFF,
                icon: UserCog,
                ability: 'staff',
            },
            {
                // A key, not a shield: warranty above already claims the shield.
                label: 'Roles & Permissions',
                href: ROUTES.ADMIN_ROLES,
                icon: KeyRound,
                ability: 'staff',
            },
            {
                label: 'Site Settings',
                href: ROUTES.ADMIN_SETTINGS,
                icon: Sliders,
                ability: 'settings',
            },
        ],
    },
    {
        label: 'Storefront',
        items: [
            {
                label: 'View Live Shop',
                href: ROUTES.HOME,
                icon: ExternalLink,
                ability: null,
                external: true,
            },
        ],
    },
];

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

    /*
     * The groups this account actually sees.
     *
     * A group with nothing left in it is dropped along with its heading, so a
     * storekeeper does not get a "Money" label sitting over empty space.
     *
     * Not memoised: it is a filter over thirty entries, which costs less than
     * the dependency array needed to skip it would.
     */
    const visibleGroups = NAV_GROUPS.map((group) => ({
        ...group,
        items: group.items.filter(
            (item) => item.ability === null || abilities.includes(item.ability),
        ),
    })).filter((group) => group.items.length > 0);

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
                    {visibleGroups.map((group) => (
                        <React.Fragment key={group.label ?? 'top'}>
                            {group.label && (
                                <div className="admin-nav-section-label admin-nav-section-spacer">
                                    {group.label}
                                </div>
                            )}

                            {group.items.map((item) => (
                                <Link
                                    key={item.href + item.label}
                                    href={item.href}
                                    className={`admin-nav-link ${
                                        !item.external && isActive(item.href)
                                            ? 'active'
                                            : ''
                                    }`}
                                    {...(item.external
                                        ? { target: '_blank' }
                                        : {})}
                                >
                                    <div className="admin-nav-left">
                                        <item.icon size={17} />
                                        <span>{item.label}</span>
                                    </div>
                                </Link>
                            ))}
                        </React.Fragment>
                    ))}
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
