import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import {
    Phone,
    Mail,
    MapPin,
    PackageSearch,
    ShieldCheck,
    Cpu,
    ChevronDown,
} from 'lucide-react';
import './Support.css';

/**
 * The support hub.
 *
 * This route existed and was linked from the header, the footer four times
 * over and the mobile drawer, but the page itself was never written — so every
 * one of those links returned a 500 from Vite, which could not resolve a page
 * component that was not there.
 *
 * What it offers is deliberately limited to things the site can actually do:
 * the real contact channels, and the tools that exist. There is no ticket
 * system behind "Customer Support Ticket" in the footer, so this does not
 * pretend there is one — it points at the warranty claim, which is real, and
 * at the hotline and inbox for everything else.
 */
export default function SupportIndex() {
    const settings = usePage().props?.site_settings || {};
    const [openFaq, setOpenFaq] = useState(0);

    const hotline = settings.hotline_number || siteConfig.hotline;
    const hotlineHours = settings.hotline_hours || siteConfig.hotlineHours;
    const supportEmail = settings.support_email || siteConfig.supportEmail;

    const channels = [
        {
            icon: Phone,
            title: 'Call the hotline',
            value: hotline,
            hint: hotlineHours,
            href: `tel:${String(hotline).replace(/[^0-9+]/g, '')}`,
        },
        {
            icon: Mail,
            title: 'Email support',
            value: supportEmail,
            hint: 'We reply within one working day',
            href: `mailto:${supportEmail}`,
        },
        {
            icon: MapPin,
            title: 'Visit a showroom',
            value: 'Find your nearest branch',
            hint: 'Walk-in service and collection',
            to: ROUTES.STORES,
        },
    ];

    const tools = [
        {
            icon: PackageSearch,
            title: 'Track an order',
            body: 'See where your parcel is with your order number.',
            to: ROUTES.TRACK,
        },
        {
            icon: ShieldCheck,
            title: 'Warranty & RMA claim',
            body: 'Check warranty status or raise a service claim.',
            to: ROUTES.WARRANTY,
        },
        {
            icon: Cpu,
            title: 'Help choosing parts',
            body: 'The builder checks that your parts fit together.',
            to: ROUTES.PC_BUILDER,
        },
    ];

    /*
     * Only answers the rest of the site already commits to — payment is cash on
     * delivery, delivery covers 64 districts, warranty claims run through the
     * RMA page. Nothing here invents a policy the shop has not stated.
     */
    const faqs = [
        {
            q: 'How can I pay for my order?',
            a: 'Cash on delivery is the only payment method at the moment. You pay the courier when the parcel reaches you, so nothing leaves your account before you have the goods in hand.',
        },
        {
            q: 'Where do you deliver?',
            a: 'All 64 districts. Express delivery is free on orders over ৳50,000; below that a delivery charge applies and is shown at checkout before you confirm.',
        },
        {
            q: 'Something arrived faulty. What now?',
            a: 'Raise a warranty claim from the Warranty & RMA page with your order number and we will arrange the service or replacement. Call the hotline first if the machine will not power on at all.',
        },
        {
            q: 'Can I cancel an order?',
            a: 'Yes, while it is still pending or processing — open the order from your dashboard and cancel it there. The stock goes straight back so somebody else can buy it.',
        },
        {
            q: 'Do you help with assembly?',
            a: 'Custom builds include professional assembly, cable management and a burn-in stress test before the machine ships. Use the PC Builder and we check part compatibility as you choose.',
        },
    ];

    return (
        <>
            <SEOHead
                title="Customer Support & Help Centre"
                description="Contact Robins Computer support: hotline, email, showroom visits, order tracking, warranty claims and answers to common questions."
            />

            <div className="container support-page">
                <div className="breadcrumbs">
                    <Link href={ROUTES.HOME}>Home</Link> &gt;{' '}
                    <span className="current">Support</span>
                </div>

                <header className="support-header">
                    <h1>How can we help?</h1>
                    <p>
                        Talk to a person, track a parcel, or claim under
                        warranty — whichever you need.
                    </p>
                </header>

                <section className="support-channels" aria-label="Contact us">
                    {channels.map(
                        ({ icon: Icon, title, value, hint, href, to }) => {
                            const inner = (
                                <>
                                    <span className="support-channel-icon">
                                        <Icon size={20} />
                                    </span>
                                    <span className="support-channel-body">
                                        <strong>{title}</strong>
                                        <span className="support-channel-value">
                                            {value}
                                        </span>
                                        <span className="support-channel-hint">
                                            {hint}
                                        </span>
                                    </span>
                                </>
                            );

                            return to ? (
                                <Link
                                    key={title}
                                    href={to}
                                    className="support-channel"
                                >
                                    {inner}
                                </Link>
                            ) : (
                                <a
                                    key={title}
                                    href={href}
                                    className="support-channel"
                                >
                                    {inner}
                                </a>
                            );
                        },
                    )}
                </section>

                <section className="support-tools" aria-label="Self service">
                    <h2 className="support-section-title">Do it yourself</h2>
                    <div className="support-tools-grid">
                        {tools.map(({ icon: Icon, title, body, to }) => (
                            <Link
                                key={title}
                                href={to}
                                className="support-tool"
                            >
                                <span className="support-tool-icon">
                                    <Icon size={18} />
                                </span>
                                <strong>{title}</strong>
                                <p>{body}</p>
                            </Link>
                        ))}
                    </div>
                </section>

                <section className="support-faq" aria-label="Common questions">
                    <h2 className="support-section-title">Common questions</h2>
                    <div className="support-faq-list">
                        {faqs.map((faq, index) => {
                            const open = openFaq === index;

                            return (
                                <div
                                    key={faq.q}
                                    className={`support-faq-item${open ? ' is-open' : ''}`}
                                >
                                    <button
                                        type="button"
                                        className="support-faq-question"
                                        aria-expanded={open}
                                        onClick={() =>
                                            setOpenFaq(open ? -1 : index)
                                        }
                                    >
                                        <span>{faq.q}</span>
                                        <ChevronDown size={18} />
                                    </button>
                                    {open && (
                                        <p className="support-faq-answer">
                                            {faq.a}
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </section>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
SupportIndex.layout = mainLayout;
