import React from 'react';
import { Link } from '@inertiajs/react';
import { ROUTES } from '../constants/endpoints';

/**
 * The brands the shop stocks (SSOT).
 *
 * Every entry used to hover as "<Brand> Official Partner" — a formal status
 * claimed fourteen times over, on a shop that is an authorised reseller for
 * some of these and not all. The tooltip says what the link does instead,
 * which is take you to that brand's products.
 */
export const BRAND_PARTNERS = [
    {
        slug: 'intel',
        name: 'Intel',
        title: 'Shop Intel',
        logo: '/images/brands/intel.png',
    },
    {
        slug: 'amd',
        name: 'AMD',
        title: 'Shop AMD',
        logo: '/images/brands/amd.png',
    },
    {
        slug: 'nvidia',
        name: 'NVIDIA',
        title: 'Shop NVIDIA',
        logo: '/images/brands/nvidia.png',
    },
    {
        slug: 'asus',
        name: 'ASUS',
        title: 'Shop ASUS',
        logo: '/images/brands/asus.png',
    },
    {
        slug: 'msi',
        name: 'MSI',
        title: 'Shop MSI',
        logo: '/images/brands/msi.png',
    },
    {
        slug: 'gigabyte',
        name: 'Gigabyte',
        title: 'Shop Gigabyte',
        logo: '/images/brands/gigabyte.png',
    },
    {
        slug: 'corsair',
        name: 'Corsair',
        title: 'Shop Corsair',
        logo: '/images/brands/corsair.png',
    },
    {
        slug: 'samsung',
        name: 'Samsung',
        title: 'Shop Samsung',
        logo: '/images/brands/samsung.png',
    },
    {
        slug: 'razer',
        name: 'Razer',
        title: 'Shop Razer',
        logo: '/images/brands/razer.png',
    },
    {
        slug: 'apple',
        name: 'Apple',
        title: 'Shop Apple',
        logo: '/images/brands/apple.png',
    },
    {
        slug: 'dell',
        name: 'Dell',
        title: 'Shop Dell',
        logo: '/images/brands/dell.png',
    },
    {
        slug: 'logitech',
        name: 'Logitech',
        title: 'Shop Logitech',
        logo: '/images/brands/logitech.png',
    },
    {
        slug: 'hp',
        name: 'HP',
        title: 'Shop HP',
        logo: '/images/brands/hp.png',
    },
    {
        slug: 'lenovo',
        name: 'Lenovo',
        title: 'Shop Lenovo',
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
