import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }) => <>{children}</>,
    usePage: () => ({
        props: { site_settings: { site_name: 'Robins Computer' } },
    }),
}));

const { default: SEOHead } = await import('../SEOHead');

/**
 * The share picture, once the page has run.
 *
 * The server writes og:image as an absolute URL, because a share card is
 * fetched by a machine with no idea which site a path belongs to. This
 * component then replaced it with the bare path it was given — so a reader
 * that runs the page (Google) was handed "/images/x.jpg", which is dropped.
 */
describe('SEOHead', () => {
    const content = (container, selector) =>
        container.querySelector(selector)?.getAttribute('content');

    it('makes a path into a full URL on this site', () => {
        const { container } = render(
            <SEOHead title="A post" image="/images/hero_banner_rog.jpg" />,
        );

        expect(content(container, 'meta[property="og:image"]')).toBe(
            `${window.location.origin}/images/hero_banner_rog.jpg`,
        );
        expect(content(container, 'meta[name="twitter:image"]')).toBe(
            `${window.location.origin}/images/hero_banner_rog.jpg`,
        );
    });

    it('leaves a URL that is already absolute alone', () => {
        const { container } = render(
            <SEOHead title="A post" image="https://cdn.example.com/a.jpg" />,
        );

        expect(content(container, 'meta[property="og:image"]')).toBe(
            'https://cdn.example.com/a.jpg',
        );
    });

    it('falls back to the shop card, also as a full URL', () => {
        const { container } = render(<SEOHead title="A post" />);

        expect(content(container, 'meta[property="og:image"]')).toBe(
            `${window.location.origin}/images/og-default.jpg`,
        );
    });

    it('says an article is an article', () => {
        const { container } = render(<SEOHead title="A post" type="article" />);

        expect(content(container, 'meta[property="og:type"]')).toBe('article');
    });
});
