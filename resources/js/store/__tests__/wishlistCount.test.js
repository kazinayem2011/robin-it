import { describe, it, expect, vi, beforeEach } from 'vitest';

const getWishlist = vi.fn();

vi.mock('../../services', () => ({
    cartService: { getCart: vi.fn() },
    compareService: { getComparison: vi.fn() },
    wishlistService: { getWishlist: (...a) => getWishlist(...a) },
}));

const { default: useAppStore } = await import('../useAppStore');

/**
 * The wishlist badge.
 *
 * Only pages that show hearts ever set it, so on Contact, the cart, or any
 * fresh load elsewhere a customer with things saved saw no count at all.
 */
describe('fetchWishlistCount', () => {
    beforeEach(() => {
        getWishlist.mockReset();
        useAppStore.setState({ wishlistCount: 0 });
    });

    it('counts what is already saved', async () => {
        getWishlist.mockResolvedValue([{ product_id: 1 }, { product_id: 2 }]);

        await useAppStore.getState().fetchWishlistCount();

        expect(useAppStore.getState().wishlistCount).toBe(2);
    });

    it('goes back to nothing when the list is empty', async () => {
        useAppStore.setState({ wishlistCount: 3 });
        getWishlist.mockResolvedValue([]);

        await useAppStore.getState().fetchWishlistCount();

        expect(useAppStore.getState().wishlistCount).toBe(0);
    });

    it('leaves the badge alone when the list cannot be read', async () => {
        useAppStore.setState({ wishlistCount: 2 });
        getWishlist.mockRejectedValue(new Error('offline'));

        await useAppStore.getState().fetchWishlistCount();

        expect(useAppStore.getState().wishlistCount).toBe(2);
    });
});
