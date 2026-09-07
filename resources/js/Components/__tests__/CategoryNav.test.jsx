import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import BrandMark from '../BrandMark';
import { hasCategoryIcon } from '../../utils/iconMap';

/**
 * Which mark a menu row gets, and what a brand's lettermark says.
 *
 * The decision has to be made by name rather than by depth: under Phone the
 * brands sit at the second level (Samsung, Redmi), under Component at the
 * third. A rule keyed on depth would put a folder glyph beside Samsung and a
 * lettermark beside Graphics Card.
 */
describe('category marks', () => {
    it('gives a real icon to rows that name a thing', () => {
        for (const name of [
            'Laptop',
            'Graphics Card',
            'Monitor',
            'Router',
            'UPS',
            'Tablet',
            'Software',
            'Camera Tripod',
            'HDMI Cable',
            'Smart Watch',
        ]) {
            expect(hasCategoryIcon(name), `${name} should have an icon`).toBe(
                true,
            );
        }
    });

    it('leaves brand names to a lettermark', () => {
        // A drawn glyph cannot say "ASUS", and the same folder beside eleven
        // hundred brands distinguishes nothing.
        for (const name of ['ASUS', 'Gigabyte', 'Redmi', 'Zyxel', 'Netac']) {
            expect(hasCategoryIcon(name), `${name} should fall through`).toBe(
                false,
            );
        }
    });

    /**
     * The reason the whole thing is keyed on the name. Both of these are
     * second-level categories; only one of them is a brand.
     */
    it('decides by name, not by depth', () => {
        expect(hasCategoryIcon('Samsung')).toBe(false);
        expect(hasCategoryIcon('Gaming Monitor')).toBe(true);
    });
});

describe('BrandMark', () => {
    it('takes initials from the start of each word', () => {
        render(<BrandMark name="Cooler Master" />);
        expect(screen.getByText('CM')).toBeInTheDocument();
    });

    /**
     * Two letters, not one: ASUS and Antec would both read "A" and the mark
     * would distinguish nothing, which is the failure it exists to avoid.
     */
    it('uses two letters for a single word', () => {
        render(<BrandMark name="ASUS" />);
        expect(screen.getByText('AS')).toBeInTheDocument();
    });

    it('handles a name starting with a digit', () => {
        render(<BrandMark name="1STPLAYER" />);
        expect(screen.getByText('1S')).toBeInTheDocument();
    });

    /**
     * Hashed rather than random, so a brand keeps its colour between renders
     * and between deploys. Random would re-colour ASUS on every navigation.
     */
    it('gives a brand the same colour every time', () => {
        const { container: first } = render(<BrandMark name="Gigabyte" />);
        const { container: second } = render(<BrandMark name="Gigabyte" />);

        expect(first.firstChild.style.background).toBe(
            second.firstChild.style.background,
        );
        expect(first.firstChild.style.background).not.toBe('');
    });

    it('gives different brands different colours', () => {
        const { container: a } = render(<BrandMark name="MSI" />);
        const { container: b } = render(<BrandMark name="Corsair" />);

        expect(a.firstChild.style.background).not.toBe(
            b.firstChild.style.background,
        );
    });

    it('prefers a real logo when the shop has uploaded one', () => {
        const { container } = render(
            <BrandMark name="ASUS" logo="/images/brands/asus.png" />,
        );

        const img = container.querySelector('img');

        expect(img).not.toBeNull();
        expect(img.getAttribute('src')).toBe('/images/brands/asus.png');
        // Decorative: the brand name is the link text beside it, and announcing
        // it twice only slows a screen reader down.
        expect(img.getAttribute('alt')).toBe('');
    });
    /*
     * The chip has to be a square whatever shape the artwork is.
     *
     * app.css sets `img { height: auto }` for every image on the site, which
     * beats the height attribute — so a wide wordmark like ASUS collapsed to
     * 16x7 beside a round 16x16 lettermark, and half the list rendered as
     * pills. The inline size is what holds it.
     */
    it('keeps a logo square however wide the artwork is', () => {
        render(
            <BrandMark name="ASUS" logo="/images/brands/asus.png" size={30} />,
        );

        const img = document.querySelector('.brand-mark-logo');

        expect(img.style.width).toBe('30px');
        expect(img.style.height).toBe('30px');
    });

    /*
     * The plate under a logo does not follow the theme. These are third-party
     * marks drawn for a light ground and most are solid black, so a themed
     * plate went dark behind them and ASUS became a name with an empty circle
     * beside it. The lettermark already paints itself a fixed light tint, so
     * this puts the two on the same footing.
     */
    it('does not let the logo plate follow the theme', async () => {
        const css = await import('node:fs').then((fs) =>
            fs.readFileSync('resources/js/Components/BrandMark.css', 'utf8'),
        );

        const rule = css.slice(css.indexOf('.brand-mark-logo'));

        expect(rule).not.toMatch(/background:\s*var\(--bg-surface\)/);
        expect(rule).toMatch(/background:\s*var\(--white\)/);
    });
});
