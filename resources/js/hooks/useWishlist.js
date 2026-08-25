import { useCallback, useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { wishlistService } from '../services';
import { toast } from '../Components/Toast';
import { ROUTES } from '../constants/endpoints';

/**
 * The saved wishlist, and toggling a product in it.
 *
 * Both the shop and the home page had a toggleWishlist that only flipped an id
 * in local state. The heart filled in, nothing was ever sent, and the wishlist
 * page stayed empty — the service underneath was complete and simply never
 * called. Neither page loaded the saved list either, so a returning customer
 * saw every heart empty however many products they had saved.
 */
export const useWishlist = () => {
    const user = usePage().props?.auth?.user;
    const [wishlistIds, setWishlistIds] = useState([]);
    const [pendingId, setPendingId] = useState(null);

    // So the hearts show what is actually saved.
    useEffect(() => {
        if (!user) {
            setWishlistIds([]);
            return;
        }

        let cancelled = false;

        wishlistService
            .getWishlist()
            .then((items) => {
                if (cancelled) return;

                setWishlistIds(
                    (items || [])
                        .map((entry) => entry.product_id)
                        .filter(Boolean),
                );
            })
            .catch(() => {
                // A failed read should leave the hearts empty rather than break
                // the page around them.
            });

        return () => {
            cancelled = true;
        };
    }, [user]);

    const toggleWishlist = useCallback(
        async (productId) => {
            if (!productId) return;

            // The endpoint is behind auth, so a guest would get a 401 and a
            // heart that filled in and then quietly emptied again.
            if (!user) {
                toast.info('Sign in to save products to your wishlist.');
                router.visit(ROUTES.LOGIN);
                return;
            }

            if (pendingId === productId) return;

            setPendingId(productId);

            // Optimistic, so the heart responds immediately; rolled back below
            // if the request fails.
            const wasSaved = wishlistIds.includes(productId);

            setWishlistIds((prev) =>
                wasSaved
                    ? prev.filter((id) => id !== productId)
                    : [...prev, productId],
            );

            try {
                const result = await wishlistService.toggleWishlist(productId);

                setWishlistIds(
                    (result?.items || [])
                        .map((entry) => entry.product_id)
                        .filter(Boolean),
                );

                toast.success(
                    result?.wishlisted
                        ? 'Saved to your wishlist.'
                        : 'Removed from your wishlist.',
                );
            } catch (error) {
                setWishlistIds((prev) =>
                    wasSaved
                        ? [...prev, productId]
                        : prev.filter((id) => id !== productId),
                );
                toast.error(
                    error?.message || 'We could not update your wishlist.',
                );
            } finally {
                setPendingId(null);
            }
        },
        [user, wishlistIds, pendingId],
    );

    return { wishlistIds, toggleWishlist, pendingId };
};

export default useWishlist;
