import React from 'react';
import { Link } from '@inertiajs/react';
import { ROUTES } from '../constants/endpoints';

/**
 * Top Authorized Brand Partners Configuration (SSOT)
 */
export const BRAND_PARTNERS = [
    {
        slug: 'intel',
        name: 'Intel',
        title: 'Intel Official Partner',
        logo: '/images/brands/intel.png',
    },
    {
        slug: 'amd',
        name: 'AMD',
        title: 'AMD Official Partner',
        logo: '/images/brands/amd.png',
    },
    {
        slug: 'nvidia',
        name: 'NVIDIA',
        title: 'NVIDIA Official Partner',
        logo: '/images/brands/nvidia.png',
    },
    {
        slug: 'asus',
        name: 'ASUS',
        title: 'ASUS ROG Official Partner',
        logo: '/images/brands/asus.png',
    },
    {
        slug: 'msi',
        name: 'MSI',
        title: 'MSI Gaming Partner',
        logo: '/images/brands/msi.png',
    },
    {
        slug: 'gigabyte',
        name: 'Gigabyte',
        title: 'Gigabyte Partner',
        logo: '/images/brands/gigabyte.png',
    },
    {
        slug: 'corsair',
        name: 'Corsair',
        title: 'Corsair Partner',
        logo: '/images/brands/corsair.png',
    },
    {
        slug: 'samsung',
        name: 'Samsung',
        title: 'Samsung Partner',
        logo: '/images/brands/samsung.png',
    },
    {
        slug: 'razer',
        name: 'Razer',
        title: 'Razer Gaming Partner',
        logo: '/images/brands/razer.png',
    },
    {
        slug: 'apple',
        name: 'Apple',
        title: 'Apple Authorised Reseller',
        logo: '/images/brands/apple.png',
    },
    {
        slug: 'dell',
        name: 'Dell',
        title: 'Dell Official Partner',
        logo: '/images/brands/dell.png',
    },
    {
        slug: 'logitech',
        name: 'Logitech',
        title: 'Logitech Partner',
        logo: '/images/brands/logitech.png',
    },
    {
        slug: 'hp',
        name: 'HP',
        title: 'HP Official Partner',
        logo: '/images/brands/hp.png',
    },
    {
        slug: 'lenovo',
        name: 'Lenovo',
        title: 'Lenovo Official Partner',
        logo: '/images/brands/lenovo.png',
    },
];

/**
 * Reusable Brand Ecosystem Logo Marquee Component (DRY / SSOT with Real Transparent PNGs)
 */
export const BrandMarquee = ({ className = '' }) => (
    <div className={`brands-logo-row ${className}`.trim()}>
        {BRAND_PARTNERS.map(({ slug, name, title, logo }) => (
            <Link
                key={slug}
                href={`${ROUTES.SHOP}?brand=${slug}`}
                className="brand-logo-pill"
                title={title}
            >
                <img
                    src={logo}
                    alt={`${name} Official Logo`}
                    className="brand-svg brand-real-logo"
                    loading="lazy"
                />
            </Link>
        ))}
    </div>
);

export default BrandMarquee;
