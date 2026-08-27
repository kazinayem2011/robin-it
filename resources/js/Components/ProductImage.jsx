import React, { useState, useEffect } from 'react';
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

    // Synchronize if product or src prop changes
    useEffect(() => {
        const nextSrc = src || getProductImageUrl(product, defaultFallback);
        setImgSrc(nextSrc);
        setHasError(false);
    }, [src, product, defaultFallback]);

    const handleError = (e) => {
        if (!hasError) {
            setHasError(true);
            setImgSrc(defaultFallback);
        }
        if (props.onError) {
            props.onError(e);
        }
    };

    const computedAlt =
        alt || product?.name || product?.title || 'Product Image';

    return (
        <img
            src={imgSrc}
            alt={computedAlt}
            className={className}
            style={style}
            loading={loading}
            width={width}
            height={height}
            onClick={onClick}
            onError={handleError}
            {...props}
        />
    );
};

export default ProductImage;
