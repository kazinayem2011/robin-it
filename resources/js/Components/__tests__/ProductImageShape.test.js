import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

/**
 * One shape, from the cropper to the card.
 *
 * The card draws its image in a 4:3 frame, the cropper cut uploads to a
 * square, and the placeholder was square too — so every product image was
 * letterboxed. Measured on the listing before this: an 800x800 upload painted
 * 189x189 inside a 253x189 box, three quarters of the width, with 32px of
 * empty box down each side.
 *
 * These three numbers have to agree, and they live in three different files —
 * a stylesheet, a page and an SVG — so nothing but a test connects them.
 */
describe('product image shape', () => {
    const RATIO = 4 / 3;

    /* The rule's declarations, with prose stripped — the comment beside them
       explains the cap that was removed, and would match a search for it. */
    const frameRule = () => {
        const css = readFileSync('resources/css/app.css', 'utf8');
        const rule = css.slice(
            css.indexOf('.product-image-box {'),
            css.indexOf('}', css.indexOf('.product-image-box {')),
        );

        return rule.replace(/\/\*[\s\S]*?\*\//g, '');
    };

    it('draws the card frame at 4:3', () => {
        expect(frameRule()).toMatch(/aspect-ratio:\s*4\s*\/\s*3/);
    });

    /*
     * Declaring the ratio is not the same as getting it, which is how this
     * shipped half-working: a max-height: 200px sat beside the aspect-ratio,
     * and on any card wider than about 267px the cap won and the box quietly
     * became a different shape. The shop's cards are 253 wide so it held
     * there; the homepage's are 322 and it did not, which is exactly why the
     * cropper fix appeared on one page and not the other.
     *
     * A height bound on a box whose height comes from its width does not limit
     * that box, it reshapes it.
     */
    it('lets nothing override the ratio it just declared', () => {
        expect(frameRule()).not.toMatch(/max-height/);
        expect(frameRule()).not.toMatch(/\bheight:/);
    });

    it('crops uploads to the same shape', () => {
        const page = readFileSync(
            'resources/js/Pages/Admin/Products.jsx',
            'utf8',
        );
        const call = page.slice(page.indexOf('<ImageCropperModal'));

        expect(call).toMatch(/aspectRatio=\{4 \/ 3\}/);
        expect(call).not.toMatch(/aspectRatio=\{1\}/);
    });

    /* A square placeholder in a 4:3 frame is the same bug with no photo. */
    it('ships a placeholder of the same shape', () => {
        const svg = readFileSync(
            'public/images/product-placeholder.svg',
            'utf8',
        );
        const [, w, h] = svg.match(/viewBox="0 0 (\d+) (\d+)"/).map(Number);

        expect(w / h).toBeCloseTo(RATIO, 2);
    });

    /*
     * The frame keeps object-fit: contain. Filling it instead would end the
     * letterboxing too, by cropping the photo — which loses the ends of a
     * graphics card and the corners of a case.
     */
    it('still fits the photo inside the frame rather than cropping it', () => {
        const css = readFileSync('resources/css/app.css', 'utf8');
        const rule = css.slice(
            css.indexOf('.product-image-box img {'),
            css.indexOf('}', css.indexOf('.product-image-box img {')),
        );

        expect(rule).toMatch(/object-fit:\s*contain/);
    });
});
