import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import {
    ShieldCheck,
    Cpu,
    Truck,
    Headphones,
    MapPin,
    ArrowRight,
} from 'lucide-react';
import Button from '../../Components/Button';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './About.css';

/**
 * @param stats Counted from what the shop actually has, rather than written
 *              into the page — a number typed into markup is out of date the
 *              week after it is written.
 */
export default function About({ stats = {}, showrooms = [] }) {
    const figures = [
        { value: stats.products, label: 'Products in stock' },
        { value: stats.brands, label: 'Brands carried' },
        { value: stats.showrooms, label: 'Showrooms' },
        { value: stats.customers, label: 'Customers served' },
    ].filter((f) => Number(f.value) > 0);

    const promises = [
        {
            icon: ShieldCheck,
            title: 'Genuine, with the warranty to prove it',
            body: 'Every part is sourced through the authorised channel and carries the manufacturer’s warranty. Claims are handled here, not sent abroad.',
        },
        {
            icon: Cpu,
            title: 'Built and tested before it ships',
            body: 'Complete machines are assembled, stress-tested and updated in our workshop. You get a PC that has already been switched on.',
        },
        {
            icon: Truck,
            title: 'Delivered across all 64 districts',
            body: 'Cash on delivery nationwide, with a tracking link from the courier the moment your parcel is handed over.',
        },
        {
            icon: Headphones,
            title: 'Advice from people who use this kit',
            body: 'Ask what will fit, what is worth the money, and what is not. The answer comes from someone who builds these every day.',
        },
    ];

    return (
        <>
            <Head title={`About Us — ${siteConfig.name}`} />

            <div className="about-page">
                <section className="about-hero">
                    <div className="container">
                        <span className="about-eyebrow">About us</span>
                        <h1>{siteConfig.name}</h1>
                        <p>
                            {siteConfig.tagline}. We sell the components,
                            laptops and complete machines that people in
                            Bangladesh actually build with — and we stand behind
                            every one of them after the sale.
                        </p>
                    </div>
                </section>

                {figures.length > 0 && (
                    <section className="container about-figures">
                        {figures.map((f) => (
                            <div key={f.label} className="about-figure">
                                <strong>
                                    {Number(f.value).toLocaleString('en-IN')}
                                </strong>
                                <span>{f.label}</span>
                            </div>
                        ))}
                    </section>
                )}

                <section className="container about-block">
                    <h2>What we promise</h2>
                    <div className="about-promises">
                        {promises.map(({ icon: Icon, title, body }) => (
                            <article key={title} className="about-promise">
                                <span className="about-promise-icon">
                                    <Icon size={19} />
                                </span>
                                <h3>{title}</h3>
                                <p>{body}</p>
                            </article>
                        ))}
                    </div>
                </section>

                {showrooms.length > 0 && (
                    <section className="container about-block">
                        <h2>Where to find us</h2>
                        <div className="about-showrooms">
                            {showrooms.map((s) => (
                                <article key={s.id} className="about-showroom">
                                    <MapPin size={16} />
                                    <div>
                                        <strong>{s.name}</strong>
                                        <span>
                                            {[s.address, s.city]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </span>
                                        {s.phone && <span>{s.phone}</span>}
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                )}

                <section className="container about-cta">
                    <div>
                        <h2>Something you want to ask?</h2>
                        <p>
                            Write to us and a person will answer, usually within
                            a working day.
                        </p>
                    </div>
                    <Link href={ROUTES.CONTACT}>
                        <Button variant="primary" size="lg" icon={ArrowRight}>
                            Contact us
                        </Button>
                    </Link>
                </section>
            </div>
        </>
    );
}

About.layout = mainLayout;
