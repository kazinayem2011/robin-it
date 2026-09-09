import { describe, it, expect } from 'vitest';
import { lineImageSrc } from '../lineImage';

/**
 * A line shows what was bought.
 *
 * An option carries its own photos, and nothing outside the product page was
 * reading them: the cart, the checkout summary and the order history all
 * rendered from the product alone. So buying the 32GB showed the generic shot,
 * and promoting a different lead photo for that option changed nothing anywhere
 * a customer looks afterwards — which is indistinguishable from the choice not
 * having saved.
 */
describe('lineImageSrc', () => {
    it('uses the option’s own photo when it has one', () => {
        expect(
            lineImageSrc({
                product: { images: [{ image_path: '/img/product.jpg' }] },
                variant: { id: 11, image_url: '/img/32gb.jpg' },
            }),
        ).toBe('/img/32gb.jpg');
    });

    /*
     * Null rather than the product's own path: the caller passes this as `src`
     * with the product still attached, so falling through gets the product's
     * lead shot and all of ProductImage's own fallbacks with it. Returning a
     * path here would duplicate that resolution and drift from it.
     */
    it('defers to the product when the option has no photo', () => {
        expect(
            lineImageSrc({
                product: { images: [{ image_path: '/img/product.jpg' }] },
                variant: { id: 11, image_url: null },
            }),
        ).toBeNull();
    });

    it('defers for a line with no option at all', () => {
        expect(
            lineImageSrc({
                product: { images: [{ image_path: '/img/product.jpg' }] },
            }),
        ).toBeNull();
    });

    it('treats an empty path as no photo', () => {
        expect(lineImageSrc({ variant: { image_url: '' } })).toBeNull();
    });

    it('survives being handed nothing', () => {
        expect(lineImageSrc(null)).toBeNull();
        expect(lineImageSrc(undefined)).toBeNull();
    });
});
