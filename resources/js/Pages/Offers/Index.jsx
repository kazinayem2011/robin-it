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
                    <span className="section-pill-tag">WHAT&rsquo;S ON</span>
                    <h1>Running Offers</h1>
                    <p>
                        Gift bundles, cashback and branch campaigns — each with
                        the dates it runs and where it applies.
                    </p>

                    {/*
                     * The other page that used to live at this address. A
                     * shopper who came looking for cut prices should not have
                     * to find out the word changed meaning.
                     *
                     * Under the description rather than flush right: the cart
                     * dock is fixed to the right edge of the window, and at
                     * common widths it sat over the end of this pill.
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
                            <div key={n} className="offer-banner is-loading">
                                <span className="offer-banner-content">
                                    <span className="offer-skeleton-line" />
                                    <span className="offer-skeleton-line is-short" />
                                </span>
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
                                /*
                                 * The same banner the homepage promos are:
                                 * artwork with the words over it, not a card
                                 * with a picture stuck on top. An offer's
                                 * poster is designed to be read, so it is the
                                 * whole tile.
                                 */
                                <Link
                                    key={offer.id}
                                    href={ROUTES.OFFER_DETAIL(offer.slug)}
                                    className="offer-banner"
                                    style={
                                        offer.image_path
                                            ? {
                                                  backgroundImage: `url(${offer.image_path})`,
                                              }
                                            : undefined
                                    }
                                >
                                    <span className="offer-banner-overlay" />

                                    {/* Only when it says something the dates
                                        below do not. */}
                                    {when.badge && (
                                        <span
                                            className={`offer-banner-badge is-${when.tone}`}
                                        >
                                            {when.badge}
                                        </span>
                                    )}

                                    <span className="offer-banner-content">
                                        <span className="offer-banner-meta">
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
                                        </span>

                                        <h2>{offer.title}</h2>

                                        {offer.excerpt && (
                                            <p>{offer.excerpt}</p>
                                        )}

                                        <span className="btn btn-primary btn-sm">
                                            View Details{' '}
                                            <ArrowRight size={14} />
                                        </span>
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

Offers.layout = (page) => mainLayout(page);
