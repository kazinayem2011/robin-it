import { describe, it, expect, vi, beforeEach } from 'vitest';

const getComparison = vi.fn();

vi.mock('../../services', () => ({
    cartService: { getCart: vi.fn() },
    compareService: {
        getComparison: (...a) => getComparison(...a),
    },
}));

const { default: useAppStore } = await import('../useAppStore');

/**
 * The compare badge.
 *
 * It was only ever set by adding something or by opening the compare page, so
 * it began every page at nought: refresh with four things in the matrix and
 * the badge vanished until you added a fifth. The cart has asked the server on
 * boot all along — this is the same, for the same reason.
 */
describe('fetchCompareCount', () => {
    beforeEach(() => {
        getComparison.mockReset();
        useAppStore.setState({ compareCount: 0 });
    });

    it('counts what is already being compared', async () => {
        getComparison.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }]);

        await useAppStore.getState().fetchCompareCount();

        expect(useAppStore.getState().compareCount).toBe(3);
    });

    /* An empty matrix is nought, not "leave whatever was there". */
    it('goes back to nothing when the matrix has been cleared', async () => {
        useAppStore.setState({ compareCount: 4 });
        getComparison.mockResolvedValue([]);

        await useAppStore.getState().fetchCompareCount();

        expect(useAppStore.getState().compareCount).toBe(0);
    });

    /* A badge is not worth breaking a page over. */
    it('leaves the badge alone when the list cannot be read', async () => {
        useAppStore.setState({ compareCount: 2 });
        getComparison.mockRejectedValue(new Error('offline'));
        const quiet = vi.spyOn(console, 'error').mockImplementation(() => {});

        await expect(
            useAppStore.getState().fetchCompareCount(),
        ).resolves.toBeUndefined();
        expect(useAppStore.getState().compareCount).toBe(2);

        quiet.mockRestore();
    });

    it('copes with a list that is not a list', async () => {
        getComparison.mockResolvedValue(null);

        await useAppStore.getState().fetchCompareCount();

        expect(useAppStore.getState().compareCount).toBe(0);
    });
});
