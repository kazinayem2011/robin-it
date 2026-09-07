import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, title }) => (
        <a href={href} title={title}>
            {children}
        </a>
    ),
}));

const { BrandMarquee, BRAND_PARTNERS } = await import('../BrandLogos');

/**
 * A pill showing the wrong company's logo is worse than one showing no logo.
 *
 * Five of these files were another brand's artwork — intel.png was PayTrace's
 * mark, msi.png was Pacific Telesis, and so on — sitting under a heading about
 * the brands this shop stocks. They are gone, and until real artwork is
 * supplied those entries draw their name instead.
 */
describe('BrandMarquee', () => {
    it('draws the mark for a brand that has one', () => {
        render(<BrandMarquee />);

        expect(screen.getByAltText('AMD logo')).toHaveAttribute(
            'src',
            '/images/brands/amd.png',
        );
    });

    it('draws the name for a brand that has none', () => {
        render(<BrandMarquee />);

        expect(screen.getByText('Intel')).toBeInTheDocument();
        expect(screen.queryByAltText('Intel logo')).not.toBeInTheDocument();
    });

    it('renders every partner either way', () => {
        render(<BrandMarquee />);

        expect(screen.getAllByRole('link')).toHaveLength(BRAND_PARTNERS.length);
    });

    it('links each one to its own products', () => {
        render(<BrandMarquee />);

        expect(screen.getByTitle('Shop Intel')).toHaveAttribute(
            'href',
            expect.stringContaining('brand=intel'),
        );
    });

    /*
     * The five removed files are the reason this exists: a logo path that
     * points at nothing renders a broken image, and one that points at the
     * wrong company renders something worse. An entry either has artwork on
     * disk or it has none — never a path to a file that is not there.
     */
    it('never points at artwork that is not on disk', async () => {
        const { existsSync } = await import('node:fs');

        const missing = BRAND_PARTNERS.filter(
            (b) => b.logo && !existsSync(`public${b.logo}`),
        );

        expect(missing.map((b) => b.slug)).toEqual([]);
    });

    /* Every entry claims nothing beyond taking you to that brand's products. */
    it('does not claim a partnership in the tooltip', () => {
        const claims = BRAND_PARTNERS.filter((b) =>
            /partner|official|authoris|authoriz/i.test(b.title),
        );

        expect(claims.map((b) => b.title)).toEqual([]);
    });
});
