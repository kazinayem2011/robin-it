import React, { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarRange,
    Store,
    ArrowRight,
    Tag,
    AlertTriangle,
    Flame,
} from 'lucide-react';
import { mainLayout } from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import EmptyState from '../../Components/EmptyState';
import CountdownTimer from '../../Components/CountdownTimer';
import { offerService } from '../../services';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import { offerWindow } from '../../utils/offerWindow';
import './Offers.css';

/**
 * One campaign, in full.
 *
 * A finished offer still renders — a link sent to a customer last week should
 * explain what it was rather than turning into a 404 — and says so plainly at
 * the top instead of quietly reading as if it were still on.
 */
export default function OfferDetail({ slug }) {
    const [offer, setOffer] = useState(null);
    const [loading, setLoading] = useState(true);
    const [expired, setExpired] = useState(false);

    useEffect(() => {
        let cancelled = false;

        setLoading(true);
        setExpired(false);

        offerService
            .getOfferBySlug(slug)
            .then((data) => {
                if (!cancelled) setOffer(data);
            })
            .catch(() => {
                if (!cancelled) setOffer(null);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [slug]);

    if (loading) {
        return (
            <div className="container offers-page">
                <div className="offer-detail-card is-loading">
                    <span className="offer-skeleton-line" />
                    <span className="offer-skeleton-line is-short" />
                </div>
            </div>
        );
    }

    if (!offer) {
        return (
            <div className="container offers-page">
                <Head title={`Offer not found — ${siteConfig.name}`} />
                <EmptyState
                    icon={Tag}
                    title="That offer is not here"
                    description="It may have been taken down. Here is everything running now."
                    actionLabel="See running offers"
                    actionHref={ROUTES.OFFERS}
                />
            </div>
        );
    }

    const when = offerWindow(offer);
    /*
     * `expired` is the clock running out while the page sits open. The server
     * said this was running when it sent it, and the page must stop saying so
     * the moment it is not.
     */
    const ended = offer.status === 'ended' || expired;

    return (
        <>
            <SEOHead
                title={offer.title}
                description={
                    offer.excerpt || `${offer.title} at ${siteConfig.name}.`
                }
            />
            <Head title={`${offer.title} — ${siteConfig.name}`} />

            <div className="container offers-page is-single">
                <div className="breadcrumbs">
                    <Link href={ROUTES.HOME}>Home</Link>
                    <Link href={ROUTES.OFFERS}>Offers</Link>
                    <span className="current">{offer.title}</span>
                </div>

                <Link href={ROUTES.OFFERS} className="offer-back">
                    <ArrowLeft size={15} /> All offers
                </Link>

                <article className="offer-detail-card">
                    {ended ? (
                        <p className="offer-ended-note">
                            <AlertTriangle size={15} />
                            This offer finished on{' '}
                            {when.range.split('– ').pop()}. It is kept here for
                            reference.
                        </p>
                    ) : (
                        when.endsAt && (
                            /*
                             * The same bar the flash deals carry on the home
                             * page, and the same CountdownTimer inside it. The
                             * shop already had one; a second hand-rolled clock
                             * is one more thing to keep in step with it.
                             */
                            <div className="offer-countdown-bar">
                                <div className="offer-countdown-left">
                                    <span className="offer-countdown-badge">
                                        <Flame
                                            size={16}
                                            className="flame-icon-pulse"
                                        />
                                        <span>LIMITED TIME</span>
                                    </span>

                                    <CountdownTimer
                                        targetDate={when.endsAt}
                                        label="ENDS IN:"
                                        variant="default"
                                        showIcon={false}
                                        onExpire={() => setExpired(true)}
                                    />
                                </div>

                                {/* The right of the bar, which the flash
                                    banner fills with "ALL DEALS". Here it is
                                    what this particular offer is for. */}
                                {offer.link_url ? (
                                    <a
                                        href={offer.link_url}
                                        className="btn btn-outline-white btn-sm"
                                    >
                                        <span>See the products</span>
                                        <ArrowRight size={14} />
                                    </a>
                                ) : (
                                    <Link
                                        href={ROUTES.OFFERS}
                                        className="btn btn-outline-white btn-sm"
                                    >
                                        <span>All offers</span>
                                        <ArrowRight size={14} />
                                    </Link>
                                )}
                            </div>
                        )
                    )}

                    {offer.image_path && (
                        <img
                            className="offer-detail-image"
                            src={offer.image_path}
                            alt={offer.title}
                        />
                    )}

                    <h1>{offer.title}</h1>

                    <p className="offer-detail-meta">
                        <span>
                            <CalendarRange size={14} />
                            {when.range}
                        </span>
                        {offer.availability && (
                            <span>
                                <Store size={14} />
                                {offer.availability}
                            </span>
                        )}
                    </p>

                    {offer.excerpt && (
                        <p className="offer-detail-lead">{offer.excerpt}</p>
                    )}

                    {offer.content && (
                        /* Cleaned by the Offer model on the way in, so what is
                           stored is already safe to render. */
                        <div
                            className="offer-detail-body"
                            dangerouslySetInnerHTML={{ __html: offer.content }}
                        />
                    )}

                    {offer.link_url && !ended && (
                        <a
                            href={offer.link_url}
                            className="btn btn-primary offer-detail-cta"
                        >
                            See the products <ArrowRight size={15} />
                        </a>
                    )}
                </article>
            </div>
        </>
    );
}

OfferDetail.layout = (page) => mainLayout(page);
