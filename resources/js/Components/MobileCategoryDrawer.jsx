import React, { useState, useEffect, memo } from 'react';
import { Link } from '@inertiajs/react';
import {
    X,
    Plus,
    Minus,
    ChevronRight,
    Cpu,
    Sparkles,
    Truck,
    ShieldCheck,
    PhoneCall,
    Tag,
} from 'lucide-react';
import { ROUTES } from '../constants/endpoints';
import { getCategoryIcon } from '../utils/iconMap';
import siteConfig from '../constants/siteConfig';

/**
 * Reusable Accordion Toggle Button (DRY helper)
 */
const AccordionToggleButton = memo(({ isOpen, onToggle, label, size = 16 }) => (
    <button
        type="button"
        className="mobile-accordion-toggle-btn"
        onClick={onToggle}
        aria-label={isOpen ? `Collapse ${label}` : `Expand ${label}`}
    >
        {isOpen ? <Minus size={size} /> : <Plus size={size} />}
    </button>
));

AccordionToggleButton.displayName = 'AccordionToggleButton';

/**
 * Mobile/Tablet Category Accordion Drawer (SSOT)
 * Provides 3-level interactive hierarchy (+ / - toggle) matching StarTech / Ryans mobile UX.
 */
export const MobileCategoryDrawer = ({ isOpen, onClose, categories = [] }) => {
    // Track expanded Level 1 parents & Level 2 subcategories
    const [expandedL1, setExpandedL1] = useState({});
    const [expandedL2, setExpandedL2] = useState({});

    // Prevent body background scroll when drawer is active
    useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => {
            document.body.style.overflow = '';
        };
    }, [isOpen]);

    const toggleL1 = (catId, e) => {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        setExpandedL1((prev) => ({
            ...prev,
            [catId]: !prev[catId],
        }));
    };

    const toggleL2 = (subId, e) => {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        setExpandedL2((prev) => ({
            ...prev,
            [subId]: !prev[subId],
        }));
    };

    if (!isOpen) return null;

    return (
        <div className="mobile-drawer-overlay" onClick={onClose}>
            {/* Slide-out Drawer Panel */}
            <div
                className="mobile-drawer-panel"
                onClick={(e) => e.stopPropagation()}
            >
                {/* 1. Drawer Header */}
                <div className="mobile-drawer-header">
                    <div className="mobile-drawer-title-group">
                        <span className="mobile-drawer-title">
                            Browse Categories
                        </span>
                        <span className="mobile-drawer-sub">
                            Explore Hardware &amp; Accessories
                        </span>
                    </div>
                    <button
                        type="button"
                        className="mobile-drawer-close-btn"
                        onClick={onClose}
                        aria-label="Close category menu"
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* 2. Scrollable Accordion Tree */}
                <div className="mobile-drawer-body">
                    <ul className="mobile-cat-tree-list">
                        {categories.map((cat) => {
                            const isL1Open = !!expandedL1[cat.id];
                            const hasSubcategories =
                                cat.subcategories &&
                                cat.subcategories.length > 0;

                            return (
                                <li
                                    key={cat.id}
                                    className={`mobile-cat-l1-item ${isL1Open ? 'l1-open' : ''}`}
                                >
                                    {/* Level 1 Row */}
                                    <div className="mobile-cat-l1-row">
                                        <Link
                                            href={ROUTES.SHOP_CATEGORY(
                                                cat.slug,
                                            )}
                                            className={`mobile-cat-l1-link ${isL1Open ? 'active-text' : ''}`}
                                            onClick={onClose}
                                        >
                                            <span className="mobile-cat-l1-icon">
                                                {getCategoryIcon(cat, {
                                                    size: 17,
                                                })}
                                            </span>
                                            <span className="mobile-cat-l1-name">
                                                {cat.name}
                                            </span>
                                            {cat.badge && (
                                                <span
                                                    className={`nav-chip-badge badge-${cat.badge.toLowerCase()}`}
                                                >
                                                    {cat.badge}
                                                </span>
                                            )}
                                        </Link>

                                        {hasSubcategories && (
                                            <AccordionToggleButton
                                                isOpen={isL1Open}
                                                onToggle={(e) =>
                                                    toggleL1(cat.id, e)
                                                }
                                                label={cat.name}
                                                size={16}
                                            />
                                        )}
                                    </div>

                                    {/* Level 2 Subcategories List */}
                                    {hasSubcategories && isL1Open && (
                                        <ul className="mobile-cat-l2-list">
                                            {/* Quick "All [Category]" Direct PLP Link */}
                                            <li className="mobile-cat-l2-item">
                                                <div className="mobile-cat-l2-row">
                                                    <Link
                                                        href={ROUTES.SHOP_CATEGORY(
                                                            cat.slug,
                                                        )}
                                                        className="mobile-cat-l2-link view-all-link"
                                                        onClick={onClose}
                                                    >
                                                        <span>
                                                            All {cat.name}
                                                        </span>
                                                    </Link>
                                                </div>
                                            </li>

                                            {cat.subcategories.map((sub) => {
                                                const isL2Open =
                                                    !!expandedL2[sub.id];
                                                const hasChildren =
                                                    sub.children &&
                                                    sub.children.length > 0;

                                                return (
                                                    <li
                                                        key={sub.id}
                                                        className={`mobile-cat-l2-item ${isL2Open ? 'l2-open' : ''}`}
                                                    >
                                                        {/* Level 2 Row */}
                                                        <div className="mobile-cat-l2-row">
                                                            <Link
                                                                href={ROUTES.SHOP_CATEGORY(
                                                                    sub.slug,
                                                                )}
                                                                className={`mobile-cat-l2-link ${isL2Open ? 'active-text' : ''}`}
                                                                onClick={
                                                                    onClose
                                                                }
                                                            >
                                                                <span className="mobile-cat-l2-icon">
                                                                    {getCategoryIcon(
                                                                        sub,
                                                                        {
                                                                            size: 14,
                                                                        },
                                                                    )}
                                                                </span>
                                                                <span className="mobile-cat-l2-name">
                                                                    {sub.name}
                                                                </span>
                                                            </Link>

                                                            {hasChildren && (
                                                                <AccordionToggleButton
                                                                    isOpen={
                                                                        isL2Open
                                                                    }
                                                                    onToggle={(
                                                                        e,
                                                                    ) =>
                                                                        toggleL2(
                                                                            sub.id,
                                                                            e,
                                                                        )
                                                                    }
                                                                    label={
                                                                        sub.name
                                                                    }
                                                                    size={14}
                                                                />
                                                            )}
                                                        </div>

                                                        {/* Level 3 Children / Series / Lineups */}
                                                        {hasChildren &&
                                                            isL2Open && (
                                                                <ul className="mobile-cat-l3-list">
                                                                    {sub.children.map(
                                                                        (
                                                                            child,
                                                                        ) => (
                                                                            <li
                                                                                key={
                                                                                    child.id
                                                                                }
                                                                                className="mobile-cat-l3-item"
                                                                            >
                                                                                <Link
                                                                                    href={ROUTES.SHOP_CATEGORY(
                                                                                        child.slug,
                                                                                    )}
                                                                                    className="mobile-cat-l3-link"
                                                                                    onClick={
                                                                                        onClose
                                                                                    }
                                                                                >
                                                                                    <span>
                                                                                        {
                                                                                            child.name
                                                                                        }
                                                                                    </span>
                                                                                    <ChevronRight
                                                                                        size={
                                                                                            13
                                                                                        }
                                                                                        className="l3-arrow"
                                                                                    />
                                                                                </Link>
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                            )}
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    {/* 3. Quick Action Hub in Drawer Footer */}
                    <div className="mobile-drawer-quick-hub">
                        <div className="mobile-drawer-hub-header">
                            Quick Shortcuts
                        </div>
                        <div className="mobile-hub-links-grid">
                            <Link
                                href={ROUTES.PC_BUILDER}
                                className="mobile-hub-card highlight"
                                onClick={onClose}
                            >
                                <Cpu size={16} />
                                <span>PC Builder</span>
                            </Link>

                            <Link
                                href={ROUTES.OFFERS}
                                className="mobile-hub-card"
                                onClick={onClose}
                            >
                                <Tag size={16} />
                                <span>Offers</span>
                            </Link>

                            {/* The two are different things: an offer is a
                                campaign the shop is running, a discount is a
                                price that has been cut. */}
                            <Link
                                href={ROUTES.DISCOUNTS}
                                className="mobile-hub-card"
                                onClick={onClose}
                            >
                                <Sparkles size={16} />
                                <span>Discounts</span>
                            </Link>

                            <Link
                                href={ROUTES.TRACK}
                                className="mobile-hub-card"
                                onClick={onClose}
                            >
                                <Truck size={16} />
                                <span>Track Order</span>
                            </Link>

                            <Link
                                href={ROUTES.SUPPORT}
                                className="mobile-hub-card"
                                onClick={onClose}
                            >
                                <ShieldCheck size={16} />
                                <span>Warranty</span>
                            </Link>
                        </div>

                        {/* Hotline Call Pill */}
                        <a
                            href={`tel:${siteConfig.hotline}`}
                            className="mobile-drawer-hotline"
                        >
                            <PhoneCall size={14} />
                            <span>
                                Call Support:{' '}
                                <strong>{siteConfig.hotline}</strong>
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default MobileCategoryDrawer;
