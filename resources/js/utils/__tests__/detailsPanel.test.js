import { describe, it, expect } from 'vitest';
import { detailsPanelFor } from '../detailsPanel';

/**
 * "View More Info" has to land on something worth reading. The panel is state,
 * not a fragment, so the choice is made before the page scrolls.
 */
describe('detailsPanelFor', () => {
    it('opens the specifications when the product has them', () => {
        expect(
            detailsPanelFor({
                specifications: [{ name: 'Socket', value: 'AM5' }],
                description: '<p>A laptop.</p>',
            }),
        ).toBe('specifications');
    });

    it('falls back to the description when there are no specifications', () => {
        expect(
            detailsPanelFor({
                specifications: [],
                description: '<p>A laptop.</p>',
            }),
        ).toBe('description');
    });

    it('treats a missing specifications key the same as an empty one', () => {
        expect(detailsPanelFor({ description: '<p>A laptop.</p>' })).toBe(
            'description',
        );
    });

    /* Landing on an empty table is worse than not offering the jump. */
    it('offers nothing when the product carries neither', () => {
        expect(
            detailsPanelFor({ specifications: [], description: '' }),
        ).toBeNull();
    });

    it('does not count whitespace as a description', () => {
        expect(
            detailsPanelFor({ specifications: [], description: '  \n ' }),
        ).toBeNull();
    });

    it('survives being handed nothing at all', () => {
        expect(detailsPanelFor(null)).toBeNull();
        expect(detailsPanelFor(undefined)).toBeNull();
    });
});
