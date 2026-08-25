import { useCallback } from 'react';
import { router } from '@inertiajs/react';
import { cartService } from '../services';
import { toast } from '../Components/Toast';
import useAppStore from '../store/useAppStore';
import { ROUTES } from '../constants/endpoints';

/**
 * Adding a product to the cart from a card.
 *
 * The shop page had this written inline and the home page had nothing at all,
 * so on the home page the cart icon and the card's button were both dead. One
 * copy, used by both.
 *
 * Returns true only when the product actually reached the cart, so a caller
 * that wants to send the shopper onward — Buy Now — can tell the difference
 * between a successful add and one that was refused.
 */
export const useAddToCart = () => {
    return useCallback(async (product) => {
        if (!product) return false;

        // A product sold by option cannot be added from a card; the shopper has
        // to pick one first.
        if (product.has_variants ?? product.hasVariants) {
            router.visit(ROUTES.PRODUCT_DETAIL(product.slug));

            return false;
        }

        try {
            await cartService.addToCart(product.id, 1);
            useAppStore.getState().fetchCartCount();
            toast.success(`Added "${product.name}" to your cart.`);

            return true;
        } catch (error) {
            toast.error(error?.message || 'Could not add that to your cart.');

            return false;
        }
    }, []);
};

export default useAddToCart;
