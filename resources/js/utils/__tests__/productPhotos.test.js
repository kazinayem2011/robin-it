import { describe, it, expect } from 'vitest';
import { photosOf } from '../productPhotos';

/**
 * One derivation, so the admin and the storefront cannot disagree about what
 * a product has. The shop worked this out inline and the admin had no list at
 * all — only the single thumbnail its rows draw.
 */
describe('photosOf', () => {
    it('reads the paths off the images', () => {
        expect(
            photosOf({
                images: [{ image_path: '/a.jpg' }, { image_path: '/b.jpg' }],
            }),
        ).toEqual(['/a.jpg', '/b.jpg']);
    });

    it('drops an image with no path rather than showing a hole', () => {
        expect(
            photosOf({
                images: [{ image_path: '/a.jpg' }, {}, { image_path: null }],
            }),
        ).toEqual(['/a.jpg']);
    });

    /* Somewhere to look beats an empty viewer, and every caller draws something. */
    it('falls back to the single path a product may carry', () => {
        expect(photosOf({ images: [], image_path: '/legacy.jpg' })).toEqual([
            '/legacy.jpg',
        ]);
    });

    it('falls back to the placeholder when there is nothing at all', () => {
        expect(photosOf({ images: [] })).toHaveLength(1);
        expect(photosOf({})).toHaveLength(1);
        expect(photosOf(null)).toHaveLength(1);
    });

    it('prefers real photos over the single path', () => {
        expect(
            photosOf({
                images: [{ image_path: '/a.jpg' }],
                image_path: '/legacy.jpg',
            }),
        ).toEqual(['/a.jpg']);
    });
});
