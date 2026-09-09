import { describe, it, expect } from 'vitest';
import { productSchemaFor } from '../productSchema';

const PRODUCT = {
    name: 'MSI Cyborg 15',
    short_description: 'A gaming laptop.',
    brand: { name: 'MSI' },
    mpn: '9S7-15K112-2423',
    model: 'Cyborg 15 A13UC',
};

const view = (over = {}) => ({
    price: 130500,
    image: '/images/x.png',
    inStock: true,
    sellerName: 'Robins Computer',
    ...over,
});

const offers = (product, over) => productSchemaFor(product, view(over)).offers;

describe('productSchemaFor', () => {
    /**
     * The bug this exists for. `availability` was the literal
     * "https://schema.org/InStock", so a sold-out product told Google it could
     * be bought — and a shopper arriving from a result that promised stock is
     * the complaint that follows.
     */
    it('says out of stock when it is out of stock', () => {
        expect(offers(PRODUCT, { inStock: false }).availability).toBe(
            'https://schema.org/OutOfStock',
        );
    });

    it('says in stock when it is in stock', () => {
        expect(offers(PRODUCT).availability).toBe('https://schema.org/InStock');
    });

    /* An empty shelf a shop will still sell from is a deferred sale, not a
       lost one, and schema.org has a state for exactly that. */
    it('says pre-order for an empty shelf the shop takes orders on', () => {
        expect(
            offers({ ...PRODUCT, allow_preorder: true }, { inStock: false })
                .availability,
        ).toBe('https://schema.org/PreOrder');
    });

    /**
     * The other half of the same bug: it published `effective_price` while the
     * page headlined `checkout_price`. The markup and the visible price have to
     * be the same number.
     */
    it('publishes the price the page is showing', () => {
        expect(offers(PRODUCT, { price: 130500 }).price).toBe(130500);
    });

    it('names the shop from its settings, not from a constant', () => {
        expect(
            offers(PRODUCT, { sellerName: 'Some Other Shop' }).seller.name,
        ).toBe('Some Other Shop');
    });

    describe('what it leaves out', () => {
        it('omits the brand rather than inventing one', () => {
            const schema = productSchemaFor(
                { ...PRODUCT, brand: null },
                view(),
            );

            expect(schema.brand).toBeUndefined();
        });

        it('omits the part number when none is recorded', () => {
            const schema = productSchemaFor(
                { ...PRODUCT, mpn: null, model: null },
                view(),
            );

            expect(schema.mpn).toBeUndefined();
            expect(schema.model).toBeUndefined();
        });

        /*
         * The page shows five stars when nothing has been reviewed. That
         * fallback reaching the markup would publish a rating the shop has not
         * earned, which is the one thing here that gets results suppressed
         * rather than ignored.
         */
        it('publishes no rating when nothing has been reviewed', () => {
            const schema = productSchemaFor(
                PRODUCT,
                view({ reviews: { total_reviews: 0, average_rating: 5 } }),
            );

            expect(schema.aggregateRating).toBeUndefined();
        });

        it('publishes the rating once there are reviews behind it', () => {
            const schema = productSchemaFor(
                PRODUCT,
                view({ reviews: { total_reviews: 12, average_rating: 4.5 } }),
            );

            expect(schema.aggregateRating).toEqual({
                '@type': 'AggregateRating',
                ratingValue: 4.5,
                reviewCount: 12,
            });
        });
    });

    it('survives being handed nothing', () => {
        expect(productSchemaFor(null, view())).toBeNull();
    });
});
