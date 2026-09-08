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

const { BrandMarquee } = await import('../BrandLogos');

/**
 * The row reads the brands table now.
 *
 * It used to be a hardcoded list of names and file paths, separate from the
 * brands an admin manages — so uploading a logo changed the mega menu and
 * never this row, and five of the files turned out to be the wrong company's
 * marks with no way to correct them short of a deploy.
 *
 * A pill showing the wrong company's logo is worse than one showing no logo,
 * so a brand with nothing on file draws its name.
 */
describe('BrandMarquee', () => {
    const brands = [
        {
            id: 1,
            name: 'AMD',
            slug: 'amd',
            logo_path: '/images/brands/amd.png',
        },
        { id: 2, name: 'Intel', slug: 'intel', logo_path: null },
        { id: 3, name: 'Gigabyte', slug: 'gigabyte', logo_path: '' },
    ];

    it('draws the mark for a brand that has one', () => {
        render(<BrandMarquee brands={brands} />);

        expect(screen.getByAltText('AMD logo')).toHaveAttribute(
            'src',
            '/images/brands/amd.png',
        );
    });

    it.each([
        ['null', 'Intel'],
        ['an empty string', 'Gigabyte'],
    ])('draws the name when logo_path is %s', (_, name) => {
        render(<BrandMarquee brands={brands} />);

        expect(screen.getByText(name)).toBeInTheDocument();
        expect(screen.queryByAltText(`${name} logo`)).not.toBeInTheDocument();
    });

    it('renders every brand it is given, either way', () => {
        render(<BrandMarquee brands={brands} />);

        expect(screen.getAllByRole('link')).toHaveLength(brands.length);
    });

    /*
     * brand_ids, which is what the shop listing filters on. These linked with
     * the slug — ?brand=intel — and the listing does not read it: the request
     * went through as a filter matching nothing and came back 0 of 0, so every
     * tile in this row led to an empty shop rather than to that brand.
     */
    it('links each one to its own products', () => {
        render(<BrandMarquee brands={brands} />);

        const href = screen.getByTitle('Shop Intel').getAttribute('href');

        expect(href).toContain('brand_ids=2');
        expect(href).not.toMatch(/brand=intel/);
    });

    /* The tooltip says what the link does. It used to claim "<Brand> Official
       Partner" for all fourteen, on a shop authorised for only some. */
    it('claims no partnership', () => {
        render(<BrandMarquee brands={brands} />);

        for (const link of screen.getAllByRole('link')) {
            expect(link.getAttribute('title')).not.toMatch(
                /partner|official|authoris|authoriz/i,
            );
        }
    });

    /* An empty featured list is a section that should not be on the page,
       not an empty grid with a heading over it. */
    it('renders nothing at all when no brand is featured', () => {
        const { container } = render(<BrandMarquee brands={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('does not fall over when given no brands prop', () => {
        const { container } = render(<BrandMarquee />);

        expect(container).toBeEmptyDOMElement();
    });
});
