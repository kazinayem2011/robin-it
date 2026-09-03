import { useCallback } from 'react';
import { router } from '@inertiajs/react';
import { cartService } from '../services';
import { toast } from '../Components/Toast';
import useAppStore from '../store/useAppStore';
import { ROUTES } from '../constants/endpoints';

/**
 * One add, whether an option was chosen or there was never a choice.
 *
 * Returns true only when the product actually reached the cart, which is what
 * lets Buy Now tell a successful add from a refused one.
 */
const addChosen = async (product, variantId = null) => {
    try {
        await cartService.addToCart(product.id, 1, variantId);
        useAppStore.getState().fetchCartCount();
        toast.success(`Added "${product.name}" to your cart.`);

        return true;
    } catch (error) {
        // The server's own words: which option is needed, or how many are
        // left. A generic sentence tells the shopper nothing they can act on.
        toast.error(error?.message || 'Could not add that to your cart.');

        return false;
    }
};

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
    return useCallback(async (product, { thenCheckout = false } = {}) => {
        if (!product) return false;

        /*
         * A product sold by option cannot be added from a card — the server
         * needs to know which option — so the shopper is asked, in a modal over
         * the list they are reading.
         *
         * This used to navigate to the product page instead, which answered the
         * question by abandoning what they were doing: on a shop page eight
         * rows down, choosing 16GB meant losing the filters, the scroll and the
         * page they were on.
         *
         * Still returns false. Nothing has reached the cart yet, and the picker
         * takes over from here — including going on to checkout when that is
         * what was asked for.
         */
        if (product.has_variants ?? product.hasVariants) {
            /*
             * One option is not a question. Plenty of products carry a single
             * option only so it can hold a serial or a colour, and asking
             * which of one to buy is a dialog with one answer — so the default
             * is added and the shopper is told which it was.
             */
            const onlyOne =
                product.variant_count === 1 && product.default_variant_id;

            if (onlyOne) {
                return addChosen(product, product.default_variant_id);
            }

            if (!product.slug) {
                // Nothing to open a picker with; the old behaviour is still
                // better than a dead click.
                router.visit(ROUTES.PRODUCT_DETAIL(product.slug));

                return false;
            }

            /*
             * Several, so the shopper picks. Deliberately not defaulted: the
             * options differ in price — 16GB and 64GB are not interchangeable
             * — and quietly adding the cheapest is a wrong purchase nobody
             * notices until the total.
             */
            useAppStore.getState().openVariantPicker({
                slug: product.slug,
                name: product.name,
                thenCheckout,
            });

            return false;
        }

        return addChosen(product);
    }, []);
};

export default useAddToCart;
