import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Cart API Service (SSOT)
 * Endpoints are centrally controlled from constants/endpoints.js
 */
export const cartService = {
    /**
     * Get active cart with items, server-calculated totals, and any stock issues.
     * @returns {Promise<{id: ?number, items: Array, totals: Object, issues: Array}>}
     */
    async getCart() {
        const response = await axiosInstance.get(API_ENDPOINTS.CART.BASE);
        const payload = response?.data || {};

        return {
            id: payload.id ?? null,
            items: payload.items || [],
            totals: payload.totals || {
                subtotal: 0,
                shipping_fee: 0,
                discount: 0,
                total: 0,
                total_items: 0,
            },
            issues: payload.issues || [],
        };
    },

    /**
     * Add an item to the cart.
     * @param {number|string} productId
     * @param {number} quantity
     */
    async addToCart(productId, quantity = 1, variantId = null) {
        const response = await axiosInstance.post(API_ENDPOINTS.CART.BASE, {
            product_id: productId,
            quantity,
            // Stock and price live on the option for a variant product, so the
            // server needs to know which one the shopper picked.
            ...(variantId ? { product_variant_id: variantId } : {}),
        });
        return response?.data || response;
    },

    /**
     * Update the quantity of an item in the cart.
     * @param {number|string} itemId
     * @param {number} quantity
     */
    async updateItemQuantity(itemId, quantity) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.CART.ITEM(itemId),
            { quantity },
        );
        return response?.data || response;
    },

    /**
     * Remove an item from the cart.
     * @param {number|string} itemId
     */
    async removeItem(itemId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.CART.ITEM(itemId),
        );
        return response?.data || response;
    },
};

export default cartService;
