import React from 'react';
import { Link } from '@inertiajs/react';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';
import useAppStore from '../store/useAppStore';

/**
 * Reusable BrandLogo component (SSOT).
 * Variants: 'header' | 'footer' | 'auth' | 'admin'
 *
 * Two files, because the mark has its strapline baked in as black ink and no
 * amount of CSS can recolour part of an image. Which one a variant gets is not
 * simply "whatever the theme is":
 *
 *   - footer and admin sit on furniture that is dark in *both* themes, so they
 *     always take the dark mark. That is a bug fix rather than a theme
 *     feature: the strapline has been invisible in the footer and down the side
 *     of the admin since before there was a theme to switch, because the
 *     surface was already near-black in the light one.
 *   - header and auth sit on a surface that follows the theme, so they follow
 *     it too.
 *
 * When no dark mark can be produced, siteConfig hands back the ordinary logo
 * and every variant renders exactly what it renders today.
 */

/** Variants whose background is dark whatever the theme is doing. */
const ALWAYS_DARK = new Set(['footer', 'admin']);

export const BrandLogo = ({
    variant = 'header',
    href = ROUTES.HOME,
    className = '',
    style = {},
    showLink = true,
}) => {
    /*
     * Subscribed rather than read once: the logo has to change in place when
     * somebody flips the theme, and this is the one part of the brand that a
     * stylesheet cannot repaint on its own.
     */
    const theme = useAppStore((state) => state.theme);

    const variantStyles = {
        header: {
            container: 'brand-logo-container',
            img: 'brand-logo-img-header',
        },
        footer: {
            container: '',
            img: 'brand-logo-img-footer',
        },
        auth: {
            container: 'auth-brand-logo',
            img: 'brand-logo-img-auth',
        },
        admin: {
            container: '',
            img: 'brand-logo-img-admin',
        },
    };

    const currentVariant = variantStyles[variant] || variantStyles.header;

    const onDark = ALWAYS_DARK.has(variant) || theme === 'dark';

    const imgElement = (
        <img
            src={onDark ? siteConfig.logo.darkSrc : siteConfig.logo.src}
            alt={siteConfig.logo.alt}
            className={`${currentVariant.img} ${className}`.trim()}
            style={style}
        />
    );

    if (!showLink) {
        return imgElement;
    }

    return (
        <Link
            href={href}
            className={currentVariant.container}
            title={`${siteConfig.name} — ${siteConfig.tagline}`}
        >
            {imgElement}
        </Link>
    );
};

export default BrandLogo;
