import React, { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { CalendarRange, Store, ArrowRight, Tag, Percent } from 'lucide-react';
import { mainLayout } from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import EmptyState from '../../Components/EmptyState';
import { offerService } from '../../services';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import { offerWindow } from '../../utils/offerWindow';
import './Offers.css';

/**
 * The campaigns the shop is running.
 *
 * `/offers` used to render the product listing with `onSaleOnly` — every
 * product whose price is cut. That is a real page and it still exists, at
 * /discounts, but it is a different thing wearing the same word: it is derived
 * from the catalogue and nobody writes it. This is what a shop announces —
 * "buy a desktop this month and get a gift" — with a window, the outlets it
 * applies at, and terms worth reading.
 */
export default function Offers() {
    const [offers, setOffers] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;

        offerService
            .getOffers()
            .then((data) => {
                if (!cancelled) setOffers(Array.isArray(data) ? data : []);
            })
            .catch(() => {
                if (!cancelled) setOffers([]);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, []);

    return (
        <>
            <SEOHead
                title="Running Offers & Campaigns"
                description="Every offer Robin's Computer is running right now — gift bundles, cashback and branch campaigns, with the dates and terms for each."
            />
            <Head title={`Offers — ${siteConfig.name}`} />

            <div className="container offers-page">
                <div className="breadcrumbs">
                    <Link href={ROUTES.HOME}>Home</Link>
                    <span className="current">Offers</span>
                </div>

                <header className="offers-head">
                    <div>
                        <span className="section-pill-tag">
                            WHAT&rsquo;S ON
                        </span>
                        <h1>Running Offers</h1>
                        <p>
                            Gift bundles, cashback and branch campaigns — each
                            with the dates it runs and where it applies.
                        </p>
                    </div>

                    {/*
                     * The other page that used to live at this address. A
                     * shopper who came looking for cut prices should not have
                     * to find out the word changed meaning.
                     */}
                    <Link
                        href={ROUTES.DISCOUNTS}
                        className="offers-to-discounts"
                    >
                        <Percent size={15} />
                        <span>Looking for discounted products?</span>
                        <ArrowRight size={14} />
                    </Link>
                </header>

                {loading ? (
                    <div className="offers-grid">
                        {[0, 1, 2].map((n) => (
                            <div key={n} className="offer-card is-loading">
                                <div className="offer-card-media" />
                                <div className="offer-card-body">
                                    <span className="offer-skeleton-line" />
                                    <span className="offer-skeleton-line is-short" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : offers.length === 0 ? (
                    <EmptyState
                        icon={Tag}
                        title="No offers running right now"
                        description="Nothing is on at the moment. Discounted products are still there, and new campaigns are announced here."
                        actionLabel="Browse discounted products"
                        actionHref={ROUTES.DISCOUNTS}
                    />
                ) : (
                    <div className="offers-grid">
                        {offers.map((offer) => {
                            const when = offerWindow(offer);

                            return (
                                <article key={offer.id} className="offer-card">
                                    <Link
                                        href={ROUTES.OFFER_DETAIL(offer.slug)}
                                        className="offer-card-media"
                                    >
                                        {offer.image_path ? (
                                            <img
                                                src={offer.image_path}
                                                alt={offer.title}
                                                loading="lazy"
                                            />
                                        ) : (
                                            <span className="offer-card-fallback">
                                                <Tag size={26} />
                                            </span>
                                        )}

                                        {/* Only when it says something the
                                            dates below do not. */}
                                        {when.badge && (
                                            <span
                                                className={`offer-card-badge is-${when.tone}`}
                                            >
                                                {when.badge}
                                            </span>
                                        )}
                                    </Link>

                                    <div className="offer-card-body">
                                        <p className="offer-card-meta">
                                            <span>
                                                <CalendarRange size={13} />
                                                {when.range}
                                            </span>
                                            {offer.availability && (
                                                <span>
                                                    <Store size={13} />
                                                    {offer.availability}
                                                </span>
                                            )}
                                        </p>

                                        <h2>
                                            <Link
                                                href={ROUTES.OFFER_DETAIL(
                                                    offer.slug,
                                                )}
                                            >
                                                {offer.title}
                                            </Link>
                                        </h2>

                                        {offer.excerpt && (
                                            <p className="offer-card-desc">
                                                {offer.excerpt}
                                            </p>
                                        )}

                                        <Link
                                            href={ROUTES.OFFER_DETAIL(
                                                offer.slug,
                                            )}
                                            className="btn btn-primary btn-sm"
                                        >
                                            View Details{' '}
                                            <ArrowRight size={14} />
                                        </Link>
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

Offers.layout = (page) => mainLayout(page);
