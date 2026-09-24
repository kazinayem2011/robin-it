import React, { useState, useEffect, useRef } from 'react';
import siteConfig from '../constants/siteConfig';

/**
 * Universal Helper: Extracts best image URL for a product with fallback (SSOT).
 */
export const getProductImageUrl = (product, customFallback = null) => {
    const fallback =
        customFallback ||
        siteConfig.productPlaceholder ||
        '/images/product-placeholder.svg';
    if (!product) return fallback;

    if (typeof product === 'string') return product || fallback;

    // image_url is the server's resolved path — already swapped for the
    // placeholder when the file is not there, so the browser is never sent a
    // URL that 404s. image_path is the raw stored value and is the fallback
    // for anything serialised before that existed.
    const img =
        product.primary_image?.image_url ||
        product.primary_image?.image_path ||
        (Array.isArray(product.images) && product.images.length > 0
            ? typeof product.images[0] === 'string'
                ? product.images[0]
                : product.images[0]?.image_url || product.images[0]?.image_path
            : null) ||
        product.image_url ||
        product.image ||
        product.image_path ||
        product.thumbnail ||
        product.thumb ||
        fallback;

    return img || fallback;
};

/*
 * The same file, however it was written. The server sends absolute URLs and
 * the fallback here is a path, so comparing the strings called them different,
 * "fell back" to the very file that had just failed, and the browser — seeing
 * the same image — never tried again or reported a second error.
 */
const sameImage = (a, b) => {
    try {
        const base =
            typeof window !== 'undefined' ? window.location.href : 'http://x/';
        return new URL(a, base).href === new URL(b, base).href;
    } catch {
        return a === b;
    }
};

/**
 * Reusable ProductImage Component (SSOT).
 * Features:
 * - Robust multi-source resolution (product object or direct src)
 * - Automatic graceful error fallback on broken/missing images
 * - Lazy loading by default
 */
export const ProductImage = ({
    product = null,
    src = null,
    alt = '',
    className = '',
    style = {},
    fallback = null,
    loading = 'lazy',
    width = undefined,
    height = undefined,
    onClick = undefined,
    ...props
}) => {
    const defaultFallback =
        fallback ||
        siteConfig.productPlaceholder ||
        '/images/product-placeholder.svg';
    const initialSrc = src || getProductImageUrl(product, defaultFallback);

    const [imgSrc, setImgSrc] = useState(initialSrc);
    const [hasError, setHasError] = useState(false);
    const [failed, setFailed] = useState(false);

    // Synchronize if product or src prop changes
    useEffect(() => {
        const nextSrc = src || getProductImageUrl(product, defaultFallback);
        setImgSrc(nextSrc);
        setHasError(false);
        setFailed(false);
    }, [src, product, defaultFallback]);

    const imgRef = useRef(null);
    const { onError: callerOnError, ...imgProps } = props;

    const handleError = (e) => {
        /*
         * Straight to the empty box when what failed was already the
         * placeholder. The server hands the placeholder itself for any product
         * whose photo is missing, and "falling back" to the same URL changes
         * nothing: the browser never tries again, no second error comes, and
         * the broken image stays with its alt text across the card.
         */
        if (!hasError && !sameImage(imgSrc, defaultFallback)) {
            setHasError(true);
            setImgSrc(defaultFallback);
        } else {
            setFailed(true);
        }
        if (callerOnError) {
            callerOnError(e);
        }
    };

    /*
     * An image that had already failed before React was listening.
     *
     * A URL the browser already knows to be broken — the placeholder, fetched
     * once for twenty cards — fails at once, and its error event can come
     * before the handler is attached. Nothing then catches it and the card is
     * stuck with the broken image. Finished with no pixels is the same thing
     * as an error. A lazy image not yet requested is not `complete`, so it is
     * left for its own event.
     */
    useEffect(() => {
        const el = imgRef.current;

        if (el && el.complete && el.naturalWidth === 0 && el.currentSrc) {
            handleError({ type: 'error', target: el, currentTarget: el });
        }
        // Checked once per source; handleError is rebuilt every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [imgSrc]);

    const computedAlt =
        alt || product?.name || product?.title || 'Product Image';

    /*
     * The placeholder failed as well — a dropped connection, a server too busy
     * to answer. A broken <img> then paints its alt text across the card, the
     * product's name in body type under the sale badge. An empty box of the
     * same size instead, which still carries the name for a screen reader.
     */
    if (failed) {
        return (
            <span
                role="img"
                aria-label={computedAlt}
                className={`${className} product-image-failed`.trim()}
                style={style}
                onClick={onClick}
            />
        );
    }

    return (
        <img
            ref={imgRef}
            src={imgSrc}
            alt={computedAlt}
            className={className}
            style={style}
            loading={loading}
            width={width}
            height={height}
            onClick={onClick}
            {...imgProps}
            // After the spread, so a caller's onError is run by handleError
            // rather than replacing it and switching the fallback off.
            onError={handleError}
        />
    );
};

export default ProductImage;
