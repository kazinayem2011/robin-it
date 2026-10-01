import { describe, it, expect } from 'vitest';
import { frameFor } from '../ImageCropperModal';

/**
 * Where a picture starts in the cropper.
 *
 * It was fitted to 85% of the canvas while the crop frame was drawn at 75%,
 * so the frame sat inside the picture and every crop lost about a tenth of
 * each edge — a 600 × 480 side card made at exactly 5:4 came back with its
 * text cut flush to the side and its logo clipped.
 */
describe('the cropper frame', () => {
    const CW = 500;
    const CH = 400;

    it('takes a picture already the right shape whole', () => {
        const f = frameFor(CW, CH, 5 / 4, { width: 1200, height: 960 });

        expect(f.dw).toBeCloseTo(f.boxW, 5);
        expect(f.dh).toBeCloseTo(f.boxH, 5);
    });

    it('trims only the overflow of a picture of another shape', () => {
        // Wider than 5:4: full height, the sides overflow.
        const wide = frameFor(CW, CH, 5 / 4, { width: 1920, height: 800 });
        expect(wide.dh).toBeCloseTo(wide.boxH, 5);
        expect(wide.dw).toBeGreaterThan(wide.boxW);

        // Taller: full width, top and bottom overflow.
        const tall = frameFor(CW, CH, 5 / 4, { width: 800, height: 1200 });
        expect(tall.dw).toBeCloseTo(tall.boxW, 5);
        expect(tall.dh).toBeGreaterThan(tall.boxH);
    });

    it('keeps the whole picture inside a free crop', () => {
        const f = frameFor(CW, CH, null, { width: 1920, height: 800 });

        expect(f.dw).toBeLessThanOrEqual(f.boxW + 1e-6);
        expect(f.dh).toBeLessThanOrEqual(f.boxH + 1e-6);
    });
});
