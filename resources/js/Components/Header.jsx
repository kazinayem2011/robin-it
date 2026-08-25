import React, { useState, useEffect, useLayoutEffect, useRef } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    Tag,
    Cpu,
    Scale,
    Heart,
    ShoppingCart,
    User,
    ChevronDown,
    PhoneCall,
    MapPin,
    Truck,
    ShieldCheck,
    ArrowRight,
    Menu,
} from 'lucide-react';
import { BrandLogo } from './BrandLogo';
import { SearchBar } from './SearchBar';
import { MobileCategoryDrawer } from './MobileCategoryDrawer';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';
import { getCategoryIcon } from '../utils/iconMap';
import { categoryService, settingService } from '../services';
import useAppStore from '../store/useAppStore';

/**
 * Reusable Site Header Component (SSOT).
 * Encapsulates Top Announcement Ticker, Main Header with Search & Actions, 3-Level Mega Menu, and Mobile Category Drawer.
 */
export const Header = () => {
    const { auth } = usePage().props;
    const [categories, setCategories] = useState([]);
    const [hoveredCategory, setHoveredCategory] = useState(null);
    const [hoveredSubCategory, setHoveredSubCategory] = useState('sc1');
    const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false);
    const [siteSettings, setSiteSettings] = useState({});
    const tickerRef = useRef(null);
    const mainHeaderRef = useRef(null);

    const cartCount = useAppStore((state) => state.cartCount);
    const fetchCartCount = useAppStore((state) => state.fetchCartCount);
    const wishlistCount = useAppStore((state) => state.wishlistCount);
    const compareCount = useAppStore((state) => state.compareCount);

    // Fetch dynamic Mega Menu Category Tree, Site Settings & sync Cart Count
    useEffect(() => {
        fetchCartCount();

        settingService
            .getSettings()
            .then((settings) => {
                if (settings) setSiteSettings(settings);
            })
            .catch(() => {});

        categoryService
            .getMegaMenu()
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setCategories(data);
                    if (data.length > 0 && data[0].subcategories?.length > 0) {
                        setHoveredSubCategory(data[0].subcategories[0].id);
                    }
                }
            })
            .catch((error) =>
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

        if (!ticker || !header || typeof ResizeObserver === 'undefined') return;

        const publish = () => {
            const root = document.documentElement.style;

            root.setProperty(
                '--site-ticker-h',
                `${Math.round(ticker.getBoundingClientRect().height)}px`,
            );
            root.setProperty(
                '--site-header-h',
                `${Math.round(header.getBoundingClientRect().height)}px`,
            );
        };

        publish();

        const observer = new ResizeObserver(publish);
        observer.observe(ticker);
        observer.observe(header);

        return () => observer.disconnect();
    }, []);

    return (
        <header className="site-header-wrapper">
            {/* 1. TOP ANNOUNCEMENT TICKER STRIP */}
            <div className="top-ticker-bar" ref={tickerRef}>
                <div className="container top-ticker-inner">
                    <div className="top-ticker-left">
                        <span className="ticker-pulse-badge">
                            <span className="live-dot"></span>{' '}
                            {siteSettings.announcement_badge || 'LIVE OFFER'}
                        </span>
                        <p className="ticker-text">
                            {siteSettings.announcement_ticker ||
                                '⚡ Flash Deals Live: Save up to 40% OFF on Gaming Laptops & Graphics Cards! Free 64-District Express Delivery on orders over ৳50,000.'}
                        </p>
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
                        <Link href={ROUTES.TRACK} className="ticker-link">
                            <Truck size={13} className="ticker-icon" />
                            <span>Track Order</span>
                        </Link>
                        <span className="ticker-divider"></span>
                        <Link href={ROUTES.STORES} className="ticker-link">
                            <MapPin size={13} className="ticker-icon" />
                            <span>Showrooms</span>
                        </Link>
                        <span className="ticker-divider"></span>
                        <Link href={ROUTES.SUPPORT} className="ticker-link">
                            <ShieldCheck size={13} className="ticker-icon" />
                            <span>Warranty Claim</span>
                        </Link>
                    </div>
                </div>
            </div>

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

                        {/* Offers Hot Button */}
                        <Link href={ROUTES.OFFERS} className="header-tool-btn">
                            <div className="tool-icon-box">
                                <Tag size={20} />
                                <span className="tool-badge-dot"></span>
                            </div>
                            <span className="tool-label">Offers</span>
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

                        {/* User Account / Sign In */}
                        {auth?.user ? (
                            <Link
                                href={
                                    auth.user.role === 'admin'
                                        ? ROUTES.ADMIN_DASHBOARD
                                        : ROUTES.DASHBOARD
                                }
                                className="header-tool-btn user-profile-btn"
                                title="My Account"
                            >
                                <div
                                    className={`tool-icon-box user-avatar-box ${auth.user.role === 'admin' ? 'user-avatar-admin' : 'user-avatar-customer'}`}
                                >
                                    <span className="user-avatar-initials">
                                        {auth.user.name
                                            ?.charAt(0)
                                            .toUpperCase()}
                                    </span>
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
            <nav className="site-mega-nav">
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

                        {/* Home link */}
                        <li className="nav-item">
                            <Link
                                href={ROUTES.HOME}
                                className="nav-link-main active-nav"
                            >
                                Home
                            </Link>
                        </li>

                        {/* Dynamic categories with 3-Level Dropdown */}
                        {categories.map((category) => (
                            <li
                                key={category.id}
                                className={`nav-item nav-item-dropdown ${hoveredCategory === category.id ? 'is-open' : ''}`}
                                onMouseEnter={() => {
                                    setHoveredCategory(category.id);
                                    if (category.subcategories?.length > 0) {
                                        setHoveredSubCategory(
                                            category.subcategories[0].id,
                                        );
                                    }
                                }}
                                onMouseLeave={() => setHoveredCategory(null)}
                            >
                                <Link
                                    href={ROUTES.SHOP_CATEGORY(category.slug)}
                                    className={`nav-link-main ${category.isOffer ? 'offer-link' : ''}`}
                                >
                                    <span>{category.name}</span>
                                    {category.badge && (
                                        <span
                                            className={`nav-chip-badge badge-${category.badge.toLowerCase()}`}
                                        >
                                            {category.badge}
                                        </span>
                                    )}
                                    {category.subcategories?.length > 0 && (
                                        <ChevronDown
                                            size={13}
                                            className="nav-chevron-icon"
                                        />
                                    )}
                                </Link>

                                {/* 3-Level Mega Menu Overlay */}
                                {category.subcategories?.length > 0 &&
                                    hoveredCategory === category.id && (
                                        <div className="mega-menu-dropdown-surface">
                                            <div
                                                className={`mega-dropdown-layout${category.promoBanner ? ' has-spotlight' : ''}`}
                                            >
                                                {/* Column 1: Level 2 Subcategories */}
                                                <div className="mega-sidebar-col">
                                                    <div className="mega-col-header">
                                                        <span>
                                                            Browse Subcategories
                                                        </span>
                                                    </div>
                                                    <ul className="mega-level2-list">
                                                        {category.subcategories.map(
                                                            (sub) => (
                                                                <li
                                                                    key={sub.id}
                                                                    className={`mega-level2-item ${hoveredSubCategory === sub.id ? 'active-level2' : ''}`}
                                                                    onMouseEnter={() =>
                                                                        setHoveredSubCategory(
                                                                            sub.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Link
                                                                        href={ROUTES.SHOP_CATEGORY(
                                                                            sub.slug,
                                                                        )}
                                                                        className="level2-link"
                                                                    >
                                                                        <span className="level2-icon">
                                                                            {getCategoryIcon(
                                                                                sub,
                                                                                {
                                                                                    size: 16,
                                                                                },
                                                                            )}
                                                                        </span>
                                                                        <span className="level2-name">
                                                                            {
                                                                                sub.name
                                                                            }
                                                                        </span>
                                                                    </Link>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </div>

                                                {/* Column 2: Level 3 Child Products/Series (Dynamic per active Level 2) */}
                                                <div className="mega-content-col">
                                                    {category.subcategories.map(
                                                        (sub) =>
                                                            hoveredSubCategory ===
                                                                sub.id && (
                                                                <div
                                                                    key={sub.id}
                                                                    className="level3-panel-container"
                                                                >
                                                                    <div className="level3-panel-header">
                                                                        <h3>
                                                                            {
                                                                                sub.name
                                                                            }{' '}
                                                                            Lineup
                                                                            &
                                                                            Series
                                                                        </h3>
                                                                        <Link
                                                                            href={ROUTES.SHOP_CATEGORY(
                                                                                sub.slug,
                                                                            )}
                                                                            className="view-all-sub-link"
                                                                        >
                                                                            View
                                                                            All{' '}
                                                                            {
                                                                                sub.name
                                                                            }{' '}
                                                                            <ArrowRight
                                                                                size={
                                                                                    13
                                                                                }
                                                                            />
                                                                        </Link>
                                                                    </div>
                                                                    <div className="level3-items-grid">
                                                                        {sub.children &&
                                                                            sub.children.map(
                                                                                (
                                                                                    child,
                                                                                ) => (
                                                                                    <Link
                                                                                        key={
                                                                                            child.id
                                                                                        }
                                                                                        href={ROUTES.SHOP_CATEGORY(
                                                                                            child.slug,
                                                                                        )}
                                                                                        className="level3-card-item"
                                                                                    >
                                                                                        <span className="child-name">
                                                                                            {
                                                                                                child.name
                                                                                            }
                                                                                        </span>
                                                                                    </Link>
                                                                                ),
                                                                            )}
                                                                    </div>
                                                                </div>
                                                            ),
                                                    )}
                                                </div>

                                                {/* Column 3: Featured Promotional Tech Spotlight Card */}
                                                {category.promoBanner && (
                                                    <div className="mega-spotlight-col">
                                                        <div className="spotlight-card">
                                                            <div className="spotlight-badge">
                                                                FEATURED
                                                                SPOTLIGHT
                                                            </div>
                                                            <div className="spotlight-img-box">
                                                                <img
                                                                    src={
                                                                        category
                                                                            .promoBanner
                                                                            .image
                                                                    }
                                                                    alt={
                                                                        category
                                                                            .promoBanner
                                                                            .title
                                                                    }
                                                                />
                                                            </div>
                                                            <h4>
                                                                {
                                                                    category
                                                                        .promoBanner
                                                                        .title
                                                                }
                                                            </h4>
                                                            <p>
                                                                {
                                                                    category
                                                                        .promoBanner
                                                                        .subtitle
                                                                }
                                                            </p>
                                                            <Link
                                                                href={
                                                                    category
                                                                        .promoBanner
                                                                        .link
                                                                }
                                                                className="spotlight-cta-btn"
                                                            >
                                                                Explore Series{' '}
                                                                <ArrowRight
                                                                    size={13}
                                                                />
                                                            </Link>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                            </li>
                        ))}
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
