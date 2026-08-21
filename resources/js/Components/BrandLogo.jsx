import React from 'react';
import { Link } from '@inertiajs/react';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';

/**
 * Reusable BrandLogo component (SSOT).
 * Variants: 'header' | 'footer' | 'auth' | 'admin'
 */
export const BrandLogo = ({
    variant = 'header',
    href = ROUTES.HOME,
    className = '',
    style = {},
    showLink = true,
}) => {
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

    const imgElement = (
        <img
            src={siteConfig.logo.src}
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
