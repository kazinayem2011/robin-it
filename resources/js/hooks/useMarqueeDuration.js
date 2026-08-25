import { useLayoutEffect } from 'react';

/**
 * Keep a marquee's speed constant however long its content is.
 *
 * A marquee is built from two identical copies so the track can travel exactly
 * one copy's width and start again with no seam. That means the distance
 * covered depends on the content, and a fixed duration would make a long
 * announcement race past while a short one crawls. This measures the first
 * copy and sets the duration from it, so the reading speed is the same either
 * way, and keeps it current as the content reflows.
 *
 * @param {object} trackRef  ref to the element holding both copies
 * @param {Array}  deps      re-measure when the content changes
 * @param {number} pixelsPerSecond
 */
export const useMarqueeDuration = (trackRef, deps = [], pixelsPerSecond = 70) => {
    useLayoutEffect(() => {
        const track = trackRef.current;

        if (!track || typeof ResizeObserver === 'undefined') return;

        const publish = () => {
            const copy = track.firstElementChild;

            if (!copy) return;

            const width = copy.getBoundingClientRect().width;

            if (width > 0) {
                track.style.setProperty(
                    '--marquee-duration',
                    `${(width / pixelsPerSecond).toFixed(1)}s`,
                );
            }
        };

        publish();

        const observer = new ResizeObserver(publish);
        observer.observe(track);

        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [trackRef, pixelsPerSecond, ...deps]);
};

export default useMarqueeDuration;
