import { describe, it, expect } from 'vitest';
import { essentialsStatus, listNames, isInStock } from '../pcBuild';

const CATEGORIES = [
    { id: 'cpu', name: 'Processor / CPU', required: true },
    { id: 'cpu-cooler', name: 'CPU Cooler', required: false },
    { id: 'motherboard', name: 'Motherboard', required: true },
    { id: 'ram', name: 'RAM (Memory)', required: true },
    { id: 'storage', name: 'Storage (SSD & HDD)', required: true },
    { id: 'graphics-card', name: 'Graphics Card (GPU)', required: false },
    { id: 'power-supply', name: 'Power Supply (PSU)', required: true },
    { id: 'pc-case', name: 'PC Case / Chassis', required: true },
    { id: 'monitors', name: 'Monitors', required: false },
];

const pick = (...ids) => ids.map((id) => ({ componentId: id, product: { id } }));

describe('essentialsStatus', () => {
    it('counts the six slots a machine cannot boot without', () => {
        expect(essentialsStatus(CATEGORIES, []).total).toBe(6);
    });

    it('reports an empty build as missing everything essential', () => {
        const status = essentialsStatus(CATEGORIES, []);

        expect(status.chosen).toBe(0);
        expect(status.complete).toBe(false);
        expect(status.missing.map((m) => m.id)).toEqual([
            'cpu',
            'motherboard',
            'ram',
            'storage',
            'power-supply',
            'pc-case',
        ]);
    });

    it('names only what is still missing', () => {
        const status = essentialsStatus(
            CATEGORIES,
            pick('cpu', 'motherboard', 'ram', 'storage'),
        );

        expect(status.chosen).toBe(4);
        expect(status.missing.map((m) => m.name)).toEqual([
            'Power Supply (PSU)',
            'PC Case / Chassis',
        ]);
    });

    it('does not count optional parts against completeness', () => {
        const status = essentialsStatus(
            CATEGORIES,
            pick(
                'cpu',
                'motherboard',
                'ram',
                'storage',
                'power-supply',
                'pc-case',
            ),
        );

        expect(status.complete).toBe(true);
        expect(status.missing).toEqual([]);
    });

    it('a build of peripherals alone is not complete', () => {
        expect(essentialsStatus(CATEGORIES, pick('monitors')).complete).toBe(
            false,
        );
    });

    /* Categories arrive from an API call, so they are briefly absent. */
    it('survives having no categories yet', () => {
        const status = essentialsStatus(undefined, undefined);

        expect(status.total).toBe(0);
        expect(status.complete).toBe(false);
        expect(status.missing).toEqual([]);
    });
});

describe('listNames', () => {
    it('reads as a sentence rather than a list', () => {
        expect(listNames([{ name: 'a PSU' }])).toBe('a PSU');
        expect(listNames([{ name: 'a PSU' }, { name: 'a case' }])).toBe(
            'a PSU and a case',
        );
        expect(
            listNames([{ name: 'RAM' }, { name: 'a PSU' }, { name: 'a case' }]),
        ).toBe('RAM, a PSU and a case');
    });
});

describe('isInStock', () => {
    /* The builder's payload spells it inStock; the catalogue spells it
     * stock_quantity. Reading one and not the other is what labelled every
     * chosen component "Out of Stock". */
    it('reads the builder payload', () => {
        expect(isInStock({ inStock: true, stockQuantity: 23 })).toBe(true);
        expect(isInStock({ inStock: false, stockQuantity: 0 })).toBe(false);
    });

    it('reads the catalogue payload', () => {
        expect(isInStock({ stock_quantity: 5 })).toBe(true);
        expect(isInStock({ stock_quantity: 0 })).toBe(false);
    });

    it('falls back to the camelCase quantity', () => {
        expect(isInStock({ stockQuantity: 2 })).toBe(true);
    });

    it('does not claim stock it was never told about', () => {
        expect(isInStock({})).toBe(false);
        expect(isInStock(null)).toBe(false);
    });
});
