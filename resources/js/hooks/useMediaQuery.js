import { useEffect, useState } from 'react';

/** The width the rest of the app calls a phone. */
export const PHONE = '(max-width: 640px)';

/**
 * Whether a media query matches, kept current as the window changes.
 *
 * For the cases CSS cannot answer, where what is rendered differs rather than
 * how it looks: Select swaps a dropdown for a bottom sheet, and Pagination
 * draws a narrower window of pages. Both need the answer in JavaScript.
 *
 * Reads false where there is no window, so server rendering gets the wide
 * layout and the first client paint corrects it.
 */
export function useMediaQuery(query) {
    const [matches, setMatches] = useState(
        () => typeof window !== 'undefined' && window.matchMedia(query).matches,
    );

    useEffect(() => {
        if (typeof window === 'undefined') return undefined;

        const mq = window.matchMedia(query);

        // Between the first render and this effect the window may already have
        // been resized, and a tablet turned sideways crosses the breakpoint.
        setMatches(mq.matches);

        const onChange = (event) => setMatches(event.matches);
        mq.addEventListener('change', onChange);

        return () => mq.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}

export const useIsPhone = () => useMediaQuery(PHONE);

export default useMediaQuery;
