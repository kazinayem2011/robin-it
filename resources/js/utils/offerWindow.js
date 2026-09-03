/**
 * How an offer's window reads on the page.
 *
 * The server sends `status`, worked out against its own clock, so the browser
 * never has to decide what is running by comparing dates in whatever timezone
 * the visitor's machine is set to. This turns that plus the two dates into the
 * line under the title and the badge over the image.
 */

const DAY = 24 * 60 * 60 * 1000;

const fmt = (value) => {
    if (!value) return null;

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) return null;

    return d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

/**
 * @returns {{range: string, badge: string|null, tone: string, endsAt: Date|null}}
 */
export const offerWindow = (offer = {}) => {
    const from = fmt(offer.starts_at);
    const to = fmt(offer.ends_at);

    const range =
        from && to
            ? `${from} – ${to}`
            : to
              ? `Until ${to}`
              : from
                ? `From ${from}`
                : 'Always on';

    const endsAt = offer.ends_at ? new Date(offer.ends_at) : null;

    if (offer.status === 'ended') {
        return { range, badge: 'Ended', tone: 'ended', endsAt };
    }

    if (offer.status === 'upcoming') {
        return {
            range,
            badge: from ? `Starts ${from}` : 'Coming soon',
            tone: 'upcoming',
            endsAt,
        };
    }

    /*
     * A badge only where it says something the dates do not. "Ends 30 Sep" next
     * to "01 Sep – 30 Sep" is the same fact twice; "Last day" is not.
     */
    if (endsAt) {
        const left = endsAt.getTime() - Date.now();

        if (left <= DAY)
            return { range, badge: 'Last day', tone: 'urgent', endsAt };
        if (left <= 3 * DAY) {
            const days = Math.ceil(left / DAY);

            return {
                range,
                badge: `${days} days left`,
                tone: 'urgent',
                endsAt,
            };
        }
    }

    return { range, badge: null, tone: 'running', endsAt };
};
