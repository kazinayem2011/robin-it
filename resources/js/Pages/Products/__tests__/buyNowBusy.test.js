import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/*
 * Buy Now has its own "working" state.
 *
 * It shared Add to Cart's, so the spinner turned up on the other button while
 * Buy Now stayed clickable — and a second click put the item in the cart
 * twice. The page is too large to render here; this holds the wiring.
 */
const src = readFileSync(
    resolve(process.cwd(), 'resources/js/Pages/Products/Show.jsx'),
    'utf8',
);

const button = (onClick) => {
    const at = src.indexOf(`onClick={${onClick}}`);
    return src.slice(src.lastIndexOf('<Button', at), src.indexOf('>', at));
};

describe('Product page — Buy Now and Add to Cart', () => {
    it('shows the spinner on Buy Now itself', () => {
        expect(button('handleBuyNow')).toContain('loading={buyingNow}');
    });

    it('locks each button while the other is working', () => {
        expect(button('handleBuyNow')).toContain('addingToCart');
        expect(button('handleAddToCart')).toContain('buyingNow');
    });

    it('ignores a second press while one is in flight', () => {
        for (const handler of ['handleBuyNow', 'handleAddToCart']) {
            const body = src.slice(src.indexOf(`const ${handler} = async`));
            expect(body.slice(0, 120)).toContain('if (busy) return;');
        }
    });
});
