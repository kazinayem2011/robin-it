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
        /* No logo: /images/brands/intel.png was PayTrace's mark, not this
           one's. Removed rather than shown — the name is drawn
           instead until real artwork is supplied. */
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
        /* No logo: /images/brands/msi.png was Pacific Telesis's mark, not this
           one's. Removed rather than shown — the name is drawn
           instead until real artwork is supplied. */
    },
    {
        slug: 'gigabyte',
        name: 'Gigabyte',
        title: 'Shop Gigabyte',
        /* No logo: /images/brands/gigabyte.png was Comcast Business's mark, not this
           one's. Removed rather than shown — the name is drawn
           instead until real artwork is supplied. */
    },
    {
        slug: 'corsair',
        name: 'Corsair',
        title: 'Shop Corsair',
        /* No logo: /images/brands/corsair.png was Orange's mark, not this
           one's. Removed rather than shown — the name is drawn
           instead until real artwork is supplied. */
    },
    {
        slug: 'samsung',
        name: 'Samsung',
        title: 'Shop Samsung',
        /* No logo: /images/brands/samsung.png was Visa's mark, not this
           one's. Removed rather than shown — the name is drawn
           instead until real artwork is supplied. */
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
                {/*
                 * The brand's own mark where the shop has one, and its name
                 * where it does not — the same rule BrandMark applies in the
                 * menu, and for the same reason: a pill that shows the wrong
                 * company's logo is worse than one that shows no logo at all.
                 *
                 * Five of these had exactly that problem. The name is set
                 * rather than a lettermark because this pill is 124px wide and
                 * "INTEL" fits where "IN" would only puzzle.
                 */}
                {logo ? (
                    <img
                        src={logo}
                        alt={`${name} logo`}
                        className="brand-svg brand-real-logo"
                        loading="lazy"
                    />
                ) : (
                    <span className="brand-wordmark">{name}</span>
                )}
            </Link>
        ))}
    </div>
);

export default BrandMarquee;
