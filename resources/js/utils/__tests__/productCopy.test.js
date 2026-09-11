import { describe, it, expect } from 'vitest';
import { withoutIdentity, copyName, CLEARED_BY_COPY } from '../productCopy';

/**
 * What a copied product may not inherit.
 *
 * The split is between describing the goods and identifying a particular one.
 * A copy keeps the description and starts empty on anything pointing at one
 * specific item, because carrying that across is not untidy, it is wrong in a
 * way somebody downstream acts on: a customer pastes an MPN into Google to
 * check they are buying the right revision.
 */
describe('withoutIdentity', () => {
    const source = {
        id: 42,
        name: 'ASUS TUF Gaming A15',
        barcode: '4711387475836',
        mpn: 'FA507NU-LP031W',
        model: 'TUF Gaming A15 FA507NU',
        price: 145000,
        warranty_text: '2 Years (Battery 1 Year)',
        category_id: 411,
        images: [{ id: 7, image_path: 'a15.jpg', is_primary: true }],
        specifications: [
            { id: 3, group: 'Processor', name: 'Model', value: 'Ryzen 7' },
        ],
        variants: [
            {
                id: 9,
                name: '16GB',
                sku: 'A15-16',
                barcode: '111',
                price: 145000,
                stock_quantity: 4,
            },
        ],
    };

    it('clears the number on this shop’s own box', () => {
        expect(withoutIdentity(source).barcode).toBe('');
    });

    it('clears the manufacturer’s part number', () => {
        expect(withoutIdentity(source).mpn).toBe('');
    });

    /**
     * Kept, deliberately. Two builds of one machine share it — the column was
     * added with exactly that in mind, "a shop that stocks both the 8GB and
     * 16GB build of one model has the same model string on two products" —
     * which is the case copying is usually for.
     */
    it('keeps the model, which two builds of one machine share', () => {
        expect(withoutIdentity(source).model).toBe('TUF Gaming A15 FA507NU');
    });

    it('keeps what describes the goods', () => {
        const copy = withoutIdentity(source);

        expect(copy.price).toBe(145000);
        expect(copy.warranty_text).toBe('2 Years (Battery 1 Year)');
        expect(copy.category_id).toBe(411);
    });

    // --- per option ------------------------------------------------------

    /* Both unique across the whole shop; a save would be refused on the first. */
    it('clears each option’s stock code and barcode', () => {
        const [option] = withoutIdentity(source).variants;

        expect(option.sku).toBe('');
        expect(option.barcode).toBe('');
        expect(option.name).toBe('16GB');
    });

    it('gives each option an empty shelf', () => {
        expect(withoutIdentity(source).variants[0].stock_quantity).toBe(0);
    });

    // --- ids -------------------------------------------------------------

    /*
     * Rows carry ids so an edit updates rather than replaces. A copy creates,
     * so an inherited id points at the original's row — the gallery in
     * particular would move the photographs rather than share them.
     */
    it('drops the ids, so nothing is taken from the original', () => {
        const copy = withoutIdentity(source);

        expect(copy.id).toBeUndefined();
        expect(copy.images[0].id).toBeUndefined();
        expect(copy.specifications[0].id).toBeUndefined();
        expect(copy.variants[0].id).toBeUndefined();
    });

    it('still carries the photographs and the spec sheet', () => {
        const copy = withoutIdentity(source);

        expect(copy.images[0].image_path).toBe('a15.jpg');
        expect(copy.specifications[0].value).toBe('Ryzen 7');
    });

    it('survives a product with none of the optional parts', () => {
        expect(() => withoutIdentity({ name: 'Bare' })).not.toThrow();
        expect(withoutIdentity({ name: 'Bare' }).variants).toEqual([]);
    });

    /* The banner names these, so it has to be able to. */
    it('explains each field it empties', () => {
        for (const entry of CLEARED_BY_COPY) {
            expect(entry.label).toBeTruthy();
            expect(entry.why).toBeTruthy();
        }
    });
});

describe('copyName', () => {
    it('marks it as a copy', () => {
        expect(copyName('RTX 4090')).toBe('RTX 4090 (Copy)');
    });

    /* Nothing is saved yet, so there is no list to be unique in. */
    it('does not stack on a copy of a copy', () => {
        expect(copyName('RTX 4090 (Copy)')).toBe('RTX 4090 (Copy)');
        expect(copyName('RTX 4090 (Copy 3)')).toBe('RTX 4090 (Copy)');
    });

    it('says nothing about nothing', () => {
        expect(copyName('')).toBe('');
    });
});
