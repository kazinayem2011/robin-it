import { describe, it, expect } from 'vitest';
import { buildProductPayload } from '../Products';

/**
 * The shape sent to the API.
 *
 * This builder names every field it sends, which makes a new field on the form
 * invisible until it is added here — and the form still takes the value, so
 * nothing looks wrong. It happened twice before the form stopped taking a
 * quantity at all: an option's photos reordered on screen while the rows never
 * moved, and an opening quantity typed against each option and dropped.
 */
const base = {
    name: 'Corsair Vengeance',
    category_id: 5,
    category_ids: [],
    price: '10500',
    stock_quantity: 0,
    specifications: [],
    related_product_ids: [],
    key_features: '',
    reorder_level: '',
    preorder_limit: '',
    preorder_release_at: '',
    warranty_months: '',
    checkout_discount: '',
    emi_max_months: '',
    discount_starts_at: '',
    discount_ends_at: '',
    min_order_quantity: 1,
    images: [],
    has_variants: false,
    variants: [],
};

const option = (over = {}) => ({
    key: 'v-1',
    id: null,
    options: { Capacity: '16GB' },
    sku: 'CV-16',
    price: '10500',
    discount_price: '',
    reorder_level: '',
    opening_stock: '4',
    is_active: true,
    images: [{ image_path: '/img/a.jpg', is_primary: true }],
    ...over,
});

describe('buildProductPayload', () => {
    it('sends a new product its options', () => {
        const out = buildProductPayload(
            { ...base, has_variants: true, variants: [option()] },
            null,
        );

        expect(out.has_variants).toBe(true);
        expect(out.variants).toHaveLength(1);
        expect(out.variants[0].sku).toBe('CV-16');
    });

    /**
     * A new product carries no quantity at all, for the product or for any of
     * its options. Stock enters under Purchasing, from a supplier or from the
     * opening-balance source.
     */
    it('sends no quantity for a new product', () => {
        const out = buildProductPayload(
            {
                ...base,
                stock_quantity: 9,
                has_variants: true,
                variants: [option()],
            },
            null,
        );

        expect(out).not.toHaveProperty('stock_quantity');
        expect(out.variants[0]).not.toHaveProperty('opening_stock');
    });

    /** The one case it is still sent: allocating a shelf that already exists. */
    it('sends it when splitting an existing product into options', () => {
        const out = buildProductPayload(
            { ...base, has_variants: true, variants: [option()] },
            { id: 7, has_variants: false },
        );

        expect(out.variants[0].opening_stock).toBe(4);
    });

    /**
     * Never on an option that already exists: that stock moves through
     * deliveries, orders and recorded adjustments, not through this form.
     */
    it('never sends it for an option that already exists', () => {
        const out = buildProductPayload(
            { ...base, has_variants: true, variants: [option({ id: 3 })] },
            { id: 7, has_variants: true },
        );

        expect(out.variants[0]).not.toHaveProperty('opening_stock');
    });

    /** The first thing this builder dropped. */
    it("sends each option's photos", () => {
        const out = buildProductPayload(
            {
                ...base,
                has_variants: true,
                variants: [
                    option({
                        images: [
                            { image_path: '/img/a.jpg', is_primary: true },
                            { image_path: '/img/b.jpg', is_primary: false },
                        ],
                    }),
                ],
            },
            null,
        );

        expect(out.variants[0].images.map((i) => i.image_path)).toEqual([
            '/img/a.jpg',
            '/img/b.jpg',
        ]);
    });

    it("sends the product's own photos", () => {
        const out = buildProductPayload(
            {
                ...base,
                images: [
                    { id: 9, image_path: '/img/one.jpg', is_primary: true },
                    { image_path: '/img/two.jpg', is_primary: false },
                ],
            },
            null,
        );

        expect(out.images.map((i) => i.image_path)).toEqual([
            '/img/one.jpg',
            '/img/two.jpg',
        ]);
        expect(out.images[0].id).toBe(9);
        expect(out.images[0].is_primary).toBe(true);
    });

    it('drops a blank photo row rather than sending an empty path', () => {
        const out = buildProductPayload(
            {
                ...base,
                images: [{ image_path: '' }, { image_path: '/img/a.jpg' }],
            },
            null,
        );

        expect(out.images).toHaveLength(1);
    });

    /** A single product carries no options, whatever is left in the rows. */
    it('sends no options when the product is not sold in them', () => {
        const out = buildProductPayload(
            { ...base, has_variants: false, variants: [option()] },
            null,
        );

        expect(out.has_variants).toBe(false);
        expect(out.variants).toBeUndefined();
    });

    /** An edit must not carry a stock figure; stock moves elsewhere. */
    it('drops the product-level quantity when editing', () => {
        const out = buildProductPayload(
            { ...base, stock_quantity: 5 },
            { id: 7 },
        );

        expect(out).not.toHaveProperty('stock_quantity');
    });
});
