import React, { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarRange,
    Store,
    ArrowRight,
    Tag,
    AlertTriangle,
} from 'lucide-react';
import { mainLayout } from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import EmptyState from '../../Components/EmptyState';
import { offerService } from '../../services';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import { offerWindow, timeLeft } from '../../utils/offerWindow';
import './Offers.css';

/** Ticks once a second, and only while there is something to count. */
function Countdown({ endsAt }) {
    const [left, setLeft] = useState(() => timeLeft(endsAt));

    useEffect(() => {
        if (!endsAt) return undefined;

        setLeft(timeLeft(endsAt));

        const id = setInterval(() => {
            const next = timeLeft(endsAt);

            setLeft(next);

            // Nothing left to count: stop, rather than run a timer for the
            // rest of the visit re-rendering the same zeroes.
            if (!next) clearInterval(id);
        }, 1000);

        return () => clearInterval(id);
    }, [endsAt]);

    if (!left) return null;

    const parts = [
        ['Days', left.days],
        ['Hours', left.hours],
        ['Minutes', left.minutes],
        ['Seconds', left.seconds],
    ];

    return (
        <div className="offer-countdown" role="timer" aria-live="off">
            <span className="offer-countdown-label">Offer ends in</span>
            <div className="offer-countdown-units">
                {parts.map(([label, value]) => (
                    <span key={label} className="offer-countdown-unit">
                        <strong>{String(value).padStart(2, '0')}</strong>
                        <small>{label}</small>
                    </span>
                ))}
            </div>
        </div>
    );
}

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

    useEffect(() => {
        let cancelled = false;

        setLoading(true);

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
    const ended = offer.status === 'ended';

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
                        <Countdown endsAt={when.endsAt} />
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
