import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import { MapPin, ArrowRight } from 'lucide-react';
import Button from '../../Components/Button';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './About.css';

/**
 * @param stats Counted from what the shop actually has, rather than written
 *              into the page — a number typed into markup is out of date the
 *              week after it is written.
 */
export default function About({ page = null, stats = {}, showrooms = [] }) {
    const figures = [
        { value: stats.products, label: 'Products in stock' },
        { value: stats.brands, label: 'Brands carried' },
        { value: stats.showrooms, label: 'Showrooms' },
        { value: stats.customers, label: 'Customers served' },
    ].filter((f) => Number(f.value) > 0);

    return (
        <>
            <Head title={`${page?.title || 'About Us'} — ${siteConfig.name}`}>
                {page?.meta_description && (
                    <meta
                        head-key="description"
                        name="description"
                        content={page.meta_description}
                    />
                )}
            </Head>

            <div className="about-page">
                <section className="about-hero">
                    <div className="container">
                        <span className="about-eyebrow">
                            {page?.title || 'About us'}
                        </span>
                        <h1>{siteConfig.name}</h1>
                        <p>{page?.subtitle || siteConfig.tagline}</p>
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

                {/*
                 * The shop's own words, edited from the admin. The figures and
                 * the showrooms below are counted rather than written, so they
                 * cannot go stale the way the footer's "15+ showrooms" did.
                 */}
                {page?.body && (
                    <section className="container about-block">
                        <div
                            className="about-prose"
                            dangerouslySetInnerHTML={{ __html: page.body }}
                        />
                    </section>
                )}

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
