import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const { BrandLogo } = await import('../BrandLogo');
const { default: useAppStore } = await import('../../store/useAppStore');
const { setBrandLogoDark } = await import('../../constants/siteConfig');

/**
 * The shop's mark is one image with the strapline baked in as black ink, and
 * CSS cannot recolour part of an image — so there are two files and something
 * has to choose between them.
 *
 * The choice is not simply "whatever the theme is". The footer is dark in
 * *both* themes, so its strapline was invisible long before there was a theme
 * to switch; it always takes the dark mark. The header, the auth card and the
 * admin sidebar follow the surface they sit on, which does follow the theme —
 * the admin sidebar is white in the light theme, where the dark mark's white
 * strapline vanished.
 */
describe('BrandLogo', () => {
    const src = () => screen.getByRole('img').getAttribute('src');

    beforeEach(() => {
        setBrandLogoDark('/images/logo-dark.png');
        useAppStore.setState({ theme: 'light' });
    });

    describe('on furniture that is dark whatever the theme is', () => {
        it('gives the footer the dark mark even in the light theme', () => {
            render(<BrandLogo variant="footer" />);

            expect(src()).toBe('/images/logo-dark.png');
        });
    });

    describe('on a surface that follows the theme', () => {
        it.each(['header', 'auth', 'admin'])(
            'gives %s the ordinary mark in light',
            (variant) => {
                render(<BrandLogo variant={variant} />);

                expect(src()).toBe('/images/logo.png');
            },
        );

        it.each(['header', 'auth', 'admin'])(
            'gives %s the dark mark in dark',
            (variant) => {
                useAppStore.setState({ theme: 'dark' });
                render(<BrandLogo variant={variant} />);

                expect(src()).toBe('/images/logo-dark.png');
            },
        );
    });

    /*
     * A shop whose logo could not be converted — a JPEG, a remote URL, a file
     * GD refused — is handed the ordinary logo for both. It must render
     * exactly what it renders today rather than ask for a file that is not
     * there and show a broken image on every page.
     */
    it('falls back to the ordinary mark when no dark one could be made', () => {
        setBrandLogoDark('/images/logo.png');
        useAppStore.setState({ theme: 'dark' });

        render(<BrandLogo variant="footer" />);

        expect(src()).toBe('/images/logo.png');
    });

    it('keeps naming the shop for a reader who cannot see either file', () => {
        render(<BrandLogo variant="footer" />);

        expect(screen.getByRole('img')).toHaveAccessibleName(
            /robins computer/i,
        );
    });
});
