import React from 'react';
import { Link } from '@inertiajs/react';
import { ROUTES } from '../constants/endpoints';

/**
 * The brands the shop stocks, on the homepage.
 *
 * This was a hardcoded list: fourteen names and fourteen file paths in this
 * file, kept entirely separately from the brands an admin manages. Two things
 * followed from that, and both bit.
 *
 * Uploading a logo in /admin/brands wrote brands.logo_path, which the mega menu
 * reads and this row did not — so the admin could change the menu and never
 * the homepage, and there was no way to correct this row without a deploy.
 * Which mattered, because five of those files were the wrong company's marks
 * altogether: intel.png was PayTrace's, msi.png was Pacific Telesis's. They
 * sat on the homepage for weeks because nobody could see them — the row sized
 * every logo by a 32px cap applied to a 512px canvas, so a wordmark padded
 * into a square drew about three pixels tall.
 *
 * It reads the table now. Which brands appear is the featured flag, ordered by
 * name, so the row is curated in the admin rather than in a constant; and a
 * brand with no logo on file draws its name, the same rule BrandMark applies
 * in the menu and for the same reason — a pill showing the wrong company's
 * logo is worse than one showing no logo at all.
 */
export const BrandMarquee = ({ brands = [], className = '' }) => {
    // Nothing featured is not an empty grid to look at; it is a section that
    // should not be on the page.
    if (!brands.length) return null;

    return (
        <div className={`brands-logo-row ${className}`.trim()}>
            {brands.map(({ id, name, slug, logo_path: logo }) => (
                <Link
                    key={id ?? slug}
                    /*
                     * brand_ids, which is the filter the shop actually reads.
                     * This linked to ?brand=<slug> and the listing came back
                     * empty — not unfiltered, empty: 0 of 0 where the shop has
                     * 1,269 products. Every tile in this row led to "no
                     * products found".
                     */
                    href={`${ROUTES.SHOP}?brand_ids=${id}`}
                    className="brand-logo-pill"
                    /* What the link does. Every entry used to claim "<Brand>
                       Official Partner" here, for all fourteen, on a shop that
                       is an authorised reseller for some of them. */
                    title={`Shop ${name}`}
                >
                    {logo ? (
                        <img
                            src={logo}
                            alt={`${name} logo`}
                            className="brand-svg"
                            loading="lazy"
                        />
                    ) : (
                        /* The name, not a lettermark: this pill is 124px wide,
                           so "Gigabyte" fits where "GI" would only puzzle. */
                        <span className="brand-wordmark">{name}</span>
                    )}
                </Link>
            ))}
        </div>
    );
};

export default BrandMarquee;
