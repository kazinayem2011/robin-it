import React, { useState, useEffect, useLayoutEffect, useRef } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    Cpu,
    Scale,
    Heart,
    ShoppingCart,
    User,
    PhoneCall,
    MapPin,
    Truck,
    Menu,
    LayoutDashboard,
} from 'lucide-react';
import { BrandLogo } from './BrandLogo';
import NotificationBell from './NotificationBell';
import { SearchBar } from './SearchBar';
import { MobileCategoryDrawer } from './MobileCategoryDrawer';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';
import CategoryNav from './CategoryNav';
import { splitAnnouncement } from '../utils/announcement';
import { useMarqueeDuration } from '../hooks';
import { categoryService } from '../services';
import { readCachedMenu, writeCachedMenu } from '../utils/menuCache';
import useAppStore from '../store/useAppStore';

/**
 * Reusable Site Header Component (SSOT).
 * Encapsulates Top Announcement Ticker, Main Header with Search & Actions, 3-Level Mega Menu, and Mobile Category Drawer.
 */
export const Header = () => {
    /*
     * The settings come down as an Inertia shared prop on every page, and the
     * header was fetching the very same map again over /api/settings on mount.
     * Two sources for one thing, one of them a round trip the page had already
     * paid for.
     */
    const { auth, site_settings: siteSettings = {} } = usePage().props;

    /*
     * Staff is "has any admin ability", not "role is admin" — the shop has
     * managers, storekeepers, support and accountants now, and abilities is
     * already shared for the admin nav.
     */
    const isStaff = (auth?.user?.abilities ?? []).length > 0;

    /*
     * Seeded from the last known menu, so a refresh paints the bar it had
     * before instead of an empty one. The fetch below still runs and corrects
     * it; this only removes the gap between the two.
     */
    const [categories, setCategories] = useState(readCachedMenu);
    const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false);
    const tickerRef = useRef(null);
    const mainHeaderRef = useRef(null);
    const megaNavRef = useRef(null);
    const marqueeRef = useRef(null);

    const cartCount = useAppStore((state) => state.cartCount);
    const fetchCartCount = useAppStore((state) => state.fetchCartCount);
    const wishlistCount = useAppStore((state) => state.wishlistCount);
    const compareCount = useAppStore((state) => state.compareCount);

    // Fetch the mega menu tree and sync the cart badge. The layout is
    // persistent now, so this runs on the first page and not on every
    // navigation after it.
    useEffect(() => {
        fetchCartCount();

        categoryService
            .getMegaMenu()
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setCategories(data);
                    writeCachedMenu(data);
                }
            })
            .catch((error) =>
                // The bar keeps whatever the cache gave it rather than
                // emptying: a stale menu beats no menu.
                console.error('Mega menu API load error:', error),
            );
    }, [fetchCartCount]);

    /*
     * The whole header block pins, pulled up by exactly the height of the
     * announcement ticker so the ticker scrolls away and the header and
     * category bar are what stay on screen.
     *
     * The ticker is 42px on a desktop and 38px on a phone, so the offset is
     * measured rather than written down, and kept current as the bar reflows.
     */
    useLayoutEffect(() => {
        const ticker = tickerRef.current;
        const header = mainHeaderRef.current;
        const nav = megaNavRef.current;

        /*
         * The ticker can be switched off in Settings, in which case there is
         * no element to measure. Everything else still has to be published —
         * bailing out here would leave --site-header-h and --site-chrome-h
         * unset, and the sticky header and every sidebar that pins beneath it
         * depend on them.
         */
        if (!header || typeof ResizeObserver === 'undefined') return;

        const publish = () => {
            const root = document.documentElement.style;
            const tickerH = ticker
                ? Math.round(ticker.getBoundingClientRect().height)
                : 0;
            const headerH = Math.round(header.getBoundingClientRect().height);
            const navH = nav
                ? Math.round(nav.getBoundingClientRect().height)
                : 0;

            root.setProperty('--site-ticker-h', `${tickerH}px`);
            root.setProperty('--site-header-h', `${headerH}px`);

            /*
             * What stays pinned once the ticker has scrolled away, and so how
             * far down the page anything else can stick without hiding behind
             * it. Below 768px only the category bar remains.
             */
            const pinned = window.innerWidth <= 768 ? navH : headerH + navH;

            root.setProperty('--site-chrome-h', `${pinned}px`);
        };

        publish();

        const observer = new ResizeObserver(publish);
        if (ticker) observer.observe(ticker);
        observer.observe(header);
        if (nav) observer.observe(nav);
        window.addEventListener('resize', publish);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', publish);
        };
    }, []);

    /*
     * announcement_text is the key the admin form writes. This read
     * announcement_ticker, which nothing has ever stored, so the ticker always
     * fell through to the line below — editing it under Settings changed
     * nothing on the site.
     */
    const announcement =
        siteSettings.announcement_text ||
        '⚡ Flash Deals Live: Save up to 40% OFF on Gaming Laptops & Graphics Cards! Free 64-District Express Delivery on orders over ৳50,000.';

    /*
     * The settings screen has an on/off switch for the ticker that nothing was
     * reading either. Stored as '1'/'0'; anything else — including the key
     * being absent on an older install — means on.
     */
    const announcementActive = siteSettings.announcement_active !== '0';

    // The heading stays put; only the offer itself travels.
    const { label: announcementLabel, message: announcementMessage } =
        splitAnnouncement(announcement);

    useMarqueeDuration(marqueeRef, [announcementMessage]);

    return (
        <header className="site-header-wrapper">
            {/* 1. TOP ANNOUNCEMENT TICKER STRIP */}
            {announcementActive && (
                <div className="top-ticker-bar" ref={tickerRef}>
                    <div className="container top-ticker-inner">
                        <div className="top-ticker-left">
                            <span className="ticker-pulse-badge">
                                <span className="live-dot"></span>{' '}
                                {siteSettings.announcement_badge ||
                                    'LIVE OFFER'}
                            </span>
                            {/*
                             * The announcement scrolls rather than being cut off
                             * mid-word by the width of the bar. The text is
                             * rendered twice so the track can travel exactly one
                             * copy's width and start over with no visible jump;
                             * the second copy is hidden from screen readers, which
                             * would otherwise announce it all again.
                             */}
                            {announcementLabel && (
                                <p className="ticker-label">
                                    {announcementLabel}
                                </p>
                            )}
                            <div className="header-marquee">
                                <div
                                    className="header-marquee-track"
                                    ref={marqueeRef}
                                >
                                    <p className="ticker-text">
                                        {announcementMessage}
                                    </p>
                                    <p
                                        className="ticker-text"
                                        aria-hidden="true"
                                    >
                                        {announcementMessage}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="top-ticker-right">
                            <a
                                href={`tel:${siteSettings.hotline_number || siteConfig.hotline}`}
                                className="ticker-link hotline-pill"
                            >
                                <PhoneCall size={13} className="ticker-icon" />
                                <span>
                                    {siteSettings.hotline_number ||
                                        siteConfig.hotline}{' '}
                                    (
                                    {siteSettings.hotline_hours ||
                                        siteConfig.hotlineHours
                                            .split('(')[0]
                                            .trim()}
                                    )
                                </span>
                            </a>
                            <span className="ticker-divider"></span>
                            <Link
                                href={ROUTES.TRACK}
                                className="ticker-link ticker-link-featured"
                            >
                                <Truck size={13} className="ticker-icon" />
                                <span>Track Order</span>
                            </Link>
                            <span className="ticker-divider"></span>
                            <Link href={ROUTES.STORES} className="ticker-link">
                                <MapPin size={13} className="ticker-icon" />
                                <span>Showrooms</span>
                            </Link>
                        </div>
                    </div>
                </div>
            )}

            {/* 2. MAIN HEADER ROW */}
            <div className="site-main-header" ref={mainHeaderRef}>
                <div className="container header-grid-container">
                    {/* Mobile Hamburger Trigger & Brand Logo */}
                    <div className="header-brand-box">
                        <button
                            type="button"
                            className="mobile-menu-trigger-btn"
                            onClick={() => setMobileDrawerOpen(true)}
                            aria-label="Open Categories Drawer"
                            title="Browse Categories"
                        >
                            <Menu size={22} />
                        </button>
                        <BrandLogo variant="header" />
                    </div>

                    {/* Central High-Conversion Search Bar (SSOT Component) */}
                    <SearchBar />

                    {/* Right E-Commerce Action Center */}
                    <div className="header-action-group">
                        {/* Signature Highlighted "PC Builder" Button */}
                        <Link
                            href={ROUTES.PC_BUILDER}
                            className="header-highlight-btn pc-builder-glow-btn"
                        >
                            <div className="btn-glow-icon">
                                <Cpu size={18} />
                            </div>
                            <div className="btn-glow-text">
                                <span className="btn-glow-sub">CUSTOM RIG</span>
                                <span className="btn-glow-title">
                                    PC BUILDER
                                </span>
                            </div>
                        </Link>

                        {/* Compare */}
                        <Link
                            href={ROUTES.COMPARE}
                            className="header-tool-btn"
                            title="Product Comparison"
                        >
                            <div className="tool-icon-box">
                                <Scale size={20} />
                                {compareCount > 0 && (
                                    <span className="tool-count-badge">
                                        {compareCount}
                                    </span>
                                )}
                            </div>
                            <span className="tool-label">Compare</span>
                        </Link>

                        {/* A shopper's own bell: how they hear that their
                            order has shipped without refreshing the page. Only
                            when signed in — there is nothing to tell a guest. */}
                        {auth.user && (
                            <div className="header-tool-btn header-tool-bell">
                                <NotificationBell userId={auth.user.id} />
                                <span className="tool-label">Alerts</span>
                            </div>
                        )}

                        {/* Wishlist */}
                        <Link
                            href={ROUTES.WISHLIST}
                            className="header-tool-btn"
                            title="Saved Wishlist"
                        >
                            <div className="tool-icon-box">
                                <Heart size={20} />
                                {wishlistCount > 0 && (
                                    <span className="tool-count-badge">
                                        {wishlistCount}
                                    </span>
                                )}
                            </div>
                            <span className="tool-label">Wishlist</span>
                        </Link>

                        {/* Shopping Cart */}
                        <Link
                            href={ROUTES.CART}
                            className="header-tool-btn"
                            title="Shopping Cart"
                        >
                            <div className="tool-icon-box">
                                <ShoppingCart size={20} />
                                {cartCount > 0 && (
                                    <span className="tool-count-badge">
                                        {cartCount}
                                    </span>
                                )}
                            </div>
                            <span className="tool-label">Cart</span>
                        </Link>

                        {/*
                         * Account goes to the customer dashboard for everyone,
                         * staff included: they buy things too, and this was
                         * the only way in — the button sent them to the admin
                         * panel instead, so an owner could not reach their own
                         * orders, wishlist or addresses. Staff get a separate
                         * button for the admin, beside it.
                         *
                         * It also tested role === 'admin', which stopped being
                         * the whole story when the shop gained managers,
                         * storekeepers, support and accountants.
                         */}
                        {auth?.user && isStaff && (
                            <Link
                                href={ROUTES.ADMIN_DASHBOARD}
                                className="header-tool-btn"
                                title="Admin panel"
                            >
                                <div className="tool-icon-box">
                                    <LayoutDashboard size={20} />
                                </div>
                                <span className="tool-label">Admin</span>
                            </Link>
                        )}

                        {/* User Account / Sign In */}
                        {auth?.user ? (
                            <Link
                                href={ROUTES.DASHBOARD}
                                className="header-tool-btn user-profile-btn"
                                title="My Account"
                            >
                                <div
                                    className={`tool-icon-box user-avatar-box ${isStaff ? 'user-avatar-admin' : 'user-avatar-customer'}`}
                                >
                                    {/* The picture where there is one; the
                                        initial is the fallback, not the rule. */}
                                    {auth.user.avatar ? (
                                        <img
                                            src={auth.user.avatar}
                                            alt={auth.user.name || 'Profile'}
                                            className="user-avatar-photo"
                                        />
                                    ) : (
                                        <span className="user-avatar-initials">
                                            {auth.user.name
                                                ?.charAt(0)
                                                .toUpperCase()}
                                        </span>
                                    )}
                                </div>
                                <span className="tool-label tool-label-ellipsis">
                                    {auth.user.name?.split(' ')[0]}
                                </span>
                            </Link>
                        ) : (
                            <Link
                                href={ROUTES.LOGIN}
                                className="header-tool-btn"
                                title="Sign In / Register"
                            >
                                <div className="tool-icon-box">
                                    <User size={20} />
                                </div>
                                <span className="tool-label">Account</span>
                            </Link>
                        )}
                    </div>
                </div>
            </div>

            {/* 3. HORIZONTAL MEGA MENU NAVIGATION BAR */}
            <nav className="site-mega-nav" ref={megaNavRef}>
                <div className="container mega-nav-container">
                    <ul className="nav-primary-list">
                        {/* Mobile Drawer Trigger Pill (Visible on small screens) */}
                        <li className="nav-item mobile-drawer-pill-item">
                            <button
                                type="button"
                                className="nav-link-main mobile-nav-drawer-btn"
                                onClick={() => setMobileDrawerOpen(true)}
                                aria-label="Open Full Category Drawer"
                            >
                                <Menu size={14} />
                                <span>All Categories</span>
                            </button>
                        </li>

                        {/* Home is not a category, and the row it sat in is the
                            category bar. The logo already goes home, which is
                            where every shop puts that link. */}

                        {/* The category bar. Was a three-column mega panel
                            with a promo card per category; that shape was
                            built for nine top-level categories and stopped
                            working at fifteen with 237 subcategories. */}
                        <CategoryNav categories={categories} />
                    </ul>
                </div>
            </nav>

            {/* 4. MOBILE / TABLET 3-LEVEL ACCORDION CATEGORY DRAWER */}
            <MobileCategoryDrawer
                isOpen={mobileDrawerOpen}
                onClose={() => setMobileDrawerOpen(false)}
                categories={categories}
            />
        </header>
    );
};

export default Header;
