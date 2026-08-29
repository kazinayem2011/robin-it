/**
 * How a deliberate scroll should travel.
 *
 * The stylesheet's prefers-reduced-motion block sets scroll-behavior: auto,
 * but that property only governs scrolls whose behaviour the browser is left
 * to decide. An explicit behavior: 'smooth' handed to scrollIntoView or
 * scrollTo overrides CSS outright, so the three places that ask for a smooth
 * scroll in JavaScript kept animating for everybody — including readers whose
 * system says plainly that movement makes them unwell.
 *
 * Returning the value rather than wrapping the call keeps both shapes honest:
 * element.scrollIntoView({ behavior: scrollBehavior(), block: 'center' }) and
 * window.scrollTo({ top: 0, behavior: scrollBehavior() }) read as themselves.
 *
 * Read per call, never cached: the preference can change while the tab is
 * open, and a value captured at import time would go stale.
 */
export const scrollBehavior = () =>
    window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
        ? 'auto'
        : 'smooth';

export default scrollBehavior;
