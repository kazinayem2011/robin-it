import { describe, it, expect } from 'vitest';
import { homepageBanners } from '../homepageBanners';

const banner = (id, position, is_active = true) => ({
    id,
    position,
    is_active,
});

/*
 * The slider took every active banner, so the three promo cards rotated
 * through it as well as sitting in their own grid: six slides, three twice.
 */
describe('homepageBanners', () => {
    it('keeps promo cards out of the hero slider', () => {
        const { hero, promos } = homepageBanners([
            banner(1, 'hero'),
            banner(2, 'promo_side'),
            banner(3, 'hero'),
            banner(4, 'promo_side'),
        ]);

        expect(hero.map((b) => b.id)).toEqual([1, 3]);
        expect(promos.map((b) => b.id)).toEqual([2, 4]);
    });

    it('leaves out anything switched off', () => {
        const { hero, promos } = homepageBanners([
            banner(1, 'hero', false),
            banner(2, 'promo_side', false),
        ]);

        expect(hero).toEqual([]);
        expect(promos).toEqual([]);
    });

    /* Offered in the admin once and shown with the cards, so kept there. */
    it('shows an old top-bar banner as a promo card, and a popup nowhere', () => {
        const { hero, promos } = homepageBanners([
            banner(1, 'promo_top'),
            banner(2, 'popup'),
        ]);

        expect(promos.map((b) => b.id)).toEqual([1]);
        expect(hero).toEqual([]);
    });

    it('copes with nothing at all', () => {
        expect(homepageBanners(null)).toEqual({ hero: [], promos: [] });
    });
});
