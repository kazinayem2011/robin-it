import React, { useCallback, useEffect, useRef } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import ProductImage from './ProductImage';

/**
 * A product's photos, large enough to look at.
 *
 * The gallery's main image is about 405px wide and was the largest view of a
 * product the shop had — while growing 4% on hover, so it advertised a click
 * it did not answer. On a laptop listing the photograph is most of what a
 * customer is buying on.
 *
 * Arrow keys move, Escape leaves, and the backdrop closes: all three are what
 * somebody tries first in a full-screen picture, and any one of them missing
 * makes it feel like a trap.
 */
export const ImageLightbox = ({
    images = [],
    index = 0,
    alt = '',
    product = null,
    onClose,
    onIndexChange,
}) => {
    const closeRef = useRef(null);
    const restoreTo = useRef(null);

    const count = images.length;
    const step = useCallback(
        (by) => {
            if (count < 2) return;

            // Wraps, because the last photo's "next" is a dead end otherwise.
            onIndexChange?.((index + by + count) % count);
        },
        [count, index, onIndexChange],
    );

    /*
     * Focus moves in and comes back out. Opening a full-screen view leaves the
     * keyboard behind the backdrop otherwise, tabbing through a page nobody
     * can see; and closing it should return the reader to the picture they
     * opened, not to the top of the document.
     */
    useEffect(() => {
        restoreTo.current = document.activeElement;
        closeRef.current?.focus();

        return () => {
            if (restoreTo.current instanceof HTMLElement) {
                restoreTo.current.focus();
            }
        };
    }, []);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose?.();
            if (event.key === 'ArrowRight') step(1);
            if (event.key === 'ArrowLeft') step(-1);
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = 'unset';
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose, step]);

    if (count === 0) return null;

    return (
        <div
            className="lightbox-backdrop"
            role="dialog"
            aria-modal="true"
            aria-label={alt ? `${alt} — photos` : 'Product photos'}
            onClick={onClose}
        >
            <button
                type="button"
                ref={closeRef}
                className="lightbox-close"
                aria-label="Close photos"
                onClick={onClose}
            >
                <X size={20} />
            </button>

            {count > 1 && (
                <p className="lightbox-count" aria-live="polite">
                    {index + 1} of {count}
                </p>
            )}

            <div
                className="lightbox-stage"
                onClick={(event) => event.stopPropagation()}
            >
                {count > 1 && (
                    <button
                        type="button"
                        className="lightbox-arrow is-back"
                        aria-label="Previous photo"
                        onClick={() => step(-1)}
                    >
                        <ChevronLeft size={22} />
                    </button>
                )}

                <div className="lightbox-figure">
                    <ProductImage
                        src={images[index]}
                        product={product}
                        alt={alt}
                    />
                </div>

                {count > 1 && (
                    <button
                        type="button"
                        className="lightbox-arrow is-next"
                        aria-label="Next photo"
                        onClick={() => step(1)}
                    >
                        <ChevronRight size={22} />
                    </button>
                )}
            </div>

            {count > 1 && (
                <div
                    className="lightbox-thumbs"
                    onClick={(event) => event.stopPropagation()}
                >
                    {images.map((image, i) => (
                        <button
                            key={i}
                            type="button"
                            className={`lightbox-thumb${i === index ? ' is-active' : ''}`}
                            aria-label={`Photo ${i + 1}`}
                            aria-current={i === index ? 'true' : undefined}
                            onClick={() => onIndexChange?.(i)}
                        >
                            <ProductImage src={image} alt="" />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

export default ImageLightbox;
