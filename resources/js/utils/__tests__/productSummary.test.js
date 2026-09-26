import { describe, it, expect } from 'vitest';
import { productSummary, shortSummary } from '../productSummary';

describe('shortSummary', () => {
    it('is the short description the shop wrote', () => {
        expect(
            shortSummary({
                name: 'HP 15-fc0626AU Laptop',
                short_description: 'Ryzen 3 7320U 15.6" FHD Copilot+PC Laptop',
            }),
        ).toBe('Ryzen 3 7320U 15.6" FHD Copilot+PC Laptop');
    });

    it('reads highlights written one per line as one line', () => {
        expect(
            shortSummary({
                name: 'Core i7',
                short_description: '20 Cores\nUp to 5.6 GHz\r\nLGA1700',
            }),
        ).toBe('20 Cores · Up to 5.6 GHz · LGA1700');
    });

    it('is empty when it is only the name again', () => {
        expect(
            shortSummary({ name: 'Mouse X', short_description: 'Mouse X' }),
        ).toBe('');
    });
});

/*
 * The descriptions here are pasted markup that open by repeating the name,
 * sometimes as it was before the listing's title was edited.
 */
describe('productSummary', () => {
    it('drops the heading that repeats the name', () => {
        expect(
            productSummary({
                name: 'HP 15 Laptop',
                description:
                    'HP 15 Laptop<div>The HP 15 is quick. It is light.</div>',
            }),
        ).toBe('The HP 15 is quick. It is light.');
    });

    it('drops a heading that is the name as it used to be', () => {
        expect(
            productSummary({
                name: 'ASUS Vivobook Go 15 Display Laptop',
                description:
                    '<b>ASUS Vivobook Go 15 Laptop</b><div>A stylish machine.</div>',
            }),
        ).toBe('A stylish machine.');
    });

    it('ends on a whole sentence within the limit', () => {
        const summary = productSummary(
            {
                name: 'X',
                description: `<p>${'One sentence here. '.repeat(30)}</p>`,
            },
            60,
        );

        expect(summary.length).toBeLessThanOrEqual(60);
        expect(summary.endsWith('.')).toBe(true);
    });

    /* It began the HP's summary at "1 GHz and four cores…". */
    it('does not end a sentence at a decimal point', () => {
        const summary = productSummary(
            {
                name: 'HP 15',
                description:
                    '<div>It boosts to 4.1 GHz on a 15.6" screen. It has four cores. ' +
                    'It is light enough to carry all day long without noticing.</div>',
            },
            70,
        );

        expect(summary).toBe(
            'It boosts to 4.1 GHz on a 15.6" screen. It has four cores.',
        );
    });

    it('cuts one long sentence at a word, not through one', () => {
        const summary = productSummary(
            { name: 'X', description: `<p>${'word '.repeat(100)}</p>` },
            40,
        );

        expect(summary.endsWith('word…')).toBe(true);
    });

    it('falls back to the short description, but not when it is just the name', () => {
        expect(
            productSummary({
                name: 'Mouse',
                short_description: 'A quiet wireless mouse.',
            }),
        ).toBe('A quiet wireless mouse.');
        expect(
            productSummary({ name: 'Mouse', short_description: 'Mouse' }),
        ).toBe('');
    });

    it('is empty with nothing to go on', () => {
        expect(productSummary(null)).toBe('');
        expect(productSummary({ name: 'Mouse' })).toBe('');
    });
});
