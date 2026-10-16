import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

/*
 * Products & Inventory: a long product name squeezed the thumbnail to a
 * sliver. The image said flex-shrink: 0, but it is the button around it that
 * sits in the row, and images are capped at 100% of their box.
 */
describe('admin product thumbnail', () => {
    it('keeps its button from shrinking beside a long name', () => {
        const css = readFileSync(
            'resources/js/Layouts/AdminLayout.css',
            'utf8',
        );
        const start = css.indexOf('.admin-product-item-thumb-open,');
        const rule = css
            .slice(start, css.indexOf('}', start))
            .replace(/\/\*[\s\S]*?\*\//g, '');

        expect(rule).toMatch(/flex:\s*none|flex-shrink:\s*0/);
    });
});
