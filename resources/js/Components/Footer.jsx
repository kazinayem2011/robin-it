import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Truck, ShieldCheck, PhoneCall, Cpu } from 'lucide-react';
import { BrandLogo } from './BrandLogo';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';

/**
 * Reusable Site Footer Component (SSOT).
 * Encapsulates Trust Badges, 5-Column Navigation Links, Newsletter Signup, and Payment/Copyright Bar.
 */
export const Footer = () => {
    // Counted from the branches that exist rather than a number written into
    // the markup, which had drifted to claiming "15+" against four.
    const showroomCount = usePage().props?.showroom_count ?? 0;

    return (
        <footer className="site-master-footer">
            {/* 4 Pillar Trust Badges Row */}
            <div className="footer-support-bar">
                <div className="container footer-support-grid">
                    <div className="support-card-item">
                        <div className="support-icon-circle">
                            <Truck size={24} />
                        </div>
                        <div className="support-info">
                            <strong>Fast Express Delivery</strong>
                            <span>Across All 64 Districts</span>
                        </div>
                    </div>
                    <div className="support-card-item">
                        <div className="support-icon-circle">
                            <ShieldCheck size={24} />
                        </div>
                        <div className="support-info">
                            <strong>100% Genuine Products</strong>
                            <span>Official Brand Warranty</span>
                        </div>
                    </div>
                    <div className="support-card-item">
                        <div className="support-icon-circle">
                            <PhoneCall size={24} />
                        </div>
                        <div className="support-info">
                            <strong>Technical Support</strong>
                            <span>Expert Buying Assistance</span>
                        </div>
                    </div>
                    <div className="support-card-item">
                        <div className="support-icon-circle">
                            <Cpu size={24} />
                        </div>
                        <div className="support-info">
                            <strong>Expert PC Assembly</strong>
                            <span>Free Cable Management & Testing</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* 5 Column Main Footer Links */}
            <div className="container footer-main-body">
                <div className="footer-nav-col">
                    <div className="footer-brand-header">
                        <BrandLogo variant="footer" />
                    </div>
                    <p className="footer-about-text">
                        {siteConfig.name} is Bangladesh's premier technology
                        marketplace for custom gaming PCs, high-end laptops,
                        genuine computer components, OLED monitors, and
                        accessories.
                    </p>
                    <div className="footer-contact-details">
                        <p>
                            <strong>Head Office:</strong>{' '}
                            {siteConfig.headOffice}
                        </p>
                        <p>
                            <strong>Email:</strong> {siteConfig.salesEmail}
                        </p>
                        <p>
                            <strong>Service Center:</strong>{' '}
                            {siteConfig.serviceCenter}
                        </p>
                    </div>
                </div>

                <div className="footer-nav-col">
                    <h5>Customer Service</h5>
                    <ul>
                        <li>
                            <Link href={ROUTES.TRACK}>Track Your Order</Link>
                        </li>
                        <li>
                            <Link href={ROUTES.WARRANTY}>
                                Warranty Policy & Claims
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SUPPORT}>
                                7 Days Return & Refund
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SUPPORT}>
                                0% EMI Available Banks
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SUPPORT}>
                                Customer Support Ticket
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SUPPORT}>
                                Submit Feedback / Complaint
                            </Link>
                        </li>
                    </ul>
                </div>

                <div className="footer-nav-col">
                    <h5>Popular Categories</h5>
                    <ul>
                        <li>
                            <Link href={ROUTES.SHOP_CATEGORY('laptops')}>
                                Gaming Laptops & MacBooks
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SHOP_CATEGORY('components')}>
                                Processors & Graphics Cards
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SHOP_CATEGORY('desktops')}>
                                Custom Built Gaming PCs
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SHOP_CATEGORY('monitors')}>
                                OLED & 240Hz Monitors
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SHOP_CATEGORY('gaming')}>
                                Mechanical Keyboards & Mice
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.PC_BUILDER}>
                                Interactive PC Builder
                            </Link>
                        </li>
                    </ul>
                </div>

                <div className="footer-nav-col">
                    <h5>About {siteConfig.name}</h5>
                    <ul>
                        <li>
                            <Link href={ROUTES.ABOUT}>About Our Company</Link>
                        </li>
                        <li>
                            <Link href={ROUTES.STORES}>
                                Showrooms &amp; Outlets
                                {showroomCount > 0 && ` (${showroomCount})`}
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.ABOUT}>Careers & Join Us</Link>
                        </li>
                        <li>
                            <Link href={ROUTES.SHOP}>
                                Tech Journal & Buying Guides
                            </Link>
                        </li>
                        <li>
                            <Link href={ROUTES.PRIVACY}>Privacy Policy</Link>
                        </li>
                        <li>
                            <Link href={ROUTES.TERMS}>Terms & Conditions</Link>
                        </li>
                    </ul>
                </div>

                <div className="footer-nav-col newsletter-col">
                    <h5>Stay In The Loop</h5>
                    <p>
                        Subscribe for exclusive flash drops, price reductions,
                        and weekly tech giveaways.
                    </p>
                    <form
                        className="footer-newsletter-form"
                        onSubmit={(e) => e.preventDefault()}
                    >
                        <input
                            type="email"
                            placeholder="Your email address..."
                        />
                        <button type="submit" className="btn btn-primary">
                            Subscribe
                        </button>
                    </form>
                    <div className="security-verified-box">
                        <span className="verified-badge">
                            <ShieldCheck size={14} /> 256-Bit SSL Encrypted &
                            Verified
                        </span>
                    </div>
                </div>
            </div>

            {/* Bottom Bar: Copyright */}
            <div className="footer-bottom-bar">
                <div className="container footer-bottom-inner">
                    {/*
                     * The closing note is the shop's own line rather than
                     * boilerplate, so it comes from Site Settings — and an
                     * admin who clears it gets the sentence dropped, not the
                     * default put back.
                     */}
                    <p className="copyright-text">
                        &copy; {new Date().getFullYear()} {siteConfig.legalName}
                        . All Rights Reserved.
                        {siteConfig.footerNote
                            ? ` ${siteConfig.footerNote}`
                            : ''}
                    </p>
                </div>
            </div>
        </footer>
    );
};

export default Footer;
