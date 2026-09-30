import { describe, it, expect } from 'vitest';
import { optionSpecs } from '../optionSpecs';

/**
 * One option's differences applied to the product's spec table.
 *
 * A laptop's Core i7 build differs from its Core i5 builds in half a dozen
 * rows. Choosing it changed only the price, so the page went on describing
 * the i5. The option's rows now replace the product's rows of the same name.
 */
describe('optionSpecs', () => {
    const product = [
        {
            group: 'Processor',
            name: 'Processor Model',
            value: 'Core i5-13420H or Core i7-13620H',
        },
        { group: 'Processor', name: 'CPU Cache', value: 'i5: 12MB; i7: 24MB' },
        { group: 'Display', name: 'Display Size', value: '15.3"' },
        { group: 'Camera', name: 'WebCam', value: 'Varies' },
    ];

    it('leaves the table alone with no option', () => {
        expect(optionSpecs(product, [])).toEqual(product);
        expect(optionSpecs(product, undefined)).toEqual(product);
    });

    it('replaces a row of the same name where it stands', () => {
        const rows = optionSpecs(product, [
            {
                group: 'Processor',
                name: 'processor  model',
                value: 'Core i7-13620H',
            },
            { name: 'WebCam', value: 'FHD 1080p + IR' },
        ]);

        expect(rows.map((r) => r.value)).toEqual([
            'Core i7-13620H',
            'i5: 12MB; i7: 24MB',
            '15.3"',
            'FHD 1080p + IR',
        ]);
        expect(rows).toHaveLength(4);
    });

    it('adds a new row after the last of its group, or at the end', () => {
        const rows = optionSpecs(product, [
            { group: 'Processor', name: 'Processor Thread', value: '16' },
            { group: 'Security', name: 'IR Camera', value: 'Yes' },
        ]);

        expect(rows.map((r) => r.name)).toEqual([
            'Processor Model',
            'CPU Cache',
            'Processor Thread',
            'Display Size',
            'WebCam',
            'IR Camera',
        ]);
    });

    it('ignores an option row with no name or no value', () => {
        expect(
            optionSpecs(product, [
                { name: '', value: 'x' },
                { name: 'WebCam', value: '' },
            ]),
        ).toEqual(product);
    });
});
