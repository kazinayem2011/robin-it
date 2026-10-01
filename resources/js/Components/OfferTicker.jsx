import React, { useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { splitAnnouncement } from '../utils/announcement';
import { useMarqueeDuration } from '../hooks';

const FALLBACK =
    '⚡ Flash Deals Live: Save up to 40% OFF on Gaming Laptops & Graphics Cards! Free 64-District Express Delivery on orders over ৳50,000.';

/**
 * The Live Offer: its badge, its fixed heading and the offer scrolling by.
 *
 * Drawn in the header's top bar and, when Settings → Header & Ticker says so,
 * again on the home page between the banner and the trust strip. One piece so
 * the two never say different things.
 *
 * The announcement scrolls rather than being cut off mid-word by the width of
 * the bar. The text is rendered twice so the track can travel exactly one
 * copy's width and start over with no visible jump; the second copy is hidden
 * from screen readers, which would otherwise announce it all again.
 */
export default function OfferTicker() {
    const { props } = usePage();
    const settings = props?.site_settings ?? {};
    const marqueeRef = useRef(null);

    // The heading stays put; only the offer itself travels.
    const { label, message } = splitAnnouncement(
        settings.announcement_text || FALLBACK,
    );

    useMarqueeDuration(marqueeRef, [message]);

    return (
        <>
            <span className="ticker-pulse-badge">
                <span className="live-dot"></span>{' '}
                {settings.announcement_badge || 'LIVE OFFER'}
            </span>
            {label && <p className="ticker-label">{label}</p>}
            <div className="header-marquee">
                <div className="header-marquee-track" ref={marqueeRef}>
                    <p className="ticker-text">{message}</p>
                    <p className="ticker-text" aria-hidden="true">
                        {message}
                    </p>
                </div>
            </div>
        </>
    );
}

/** Whether the ticker is on at all ('0' off; anything else, or absent, on). */
export const offerTickerOn = (settings = {}) =>
    settings.announcement_active !== '0';

/** Whether the home page repeats it below the banner (on unless switched off). */
export const offerTickerOnHome = (settings = {}) =>
    offerTickerOn(settings) && settings.announcement_home !== '0';
