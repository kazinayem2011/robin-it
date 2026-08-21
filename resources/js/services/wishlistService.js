import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Wishlist API Service (SSOT)
 * Endpoints are centrally controlled from constants/endpoints.js
 */
export const wishlistService = {
    async getWishlist() {
        const response = await axiosInstance.get(API_ENDPOINTS.WISHLIST.BASE);
        return response?.data || response;
    },

    async addToWishlist(productId) {
        const response = await axiosInstance.post(API_ENDPOINTS.WISHLIST.BASE, {
            product_id: productId,
        });
        return response?.data || response;
    },

    async removeFromWishlist(productId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.WISHLIST.ITEM(productId),
        );
        return response?.data || response;
    },

    /**
     * Add the product to the wishlist, or remove it if it is already there.
     *
     * The product page called this method, which did not exist — the click threw
     * a TypeError and the wishlist button did nothing.
     *
     * @returns {Promise<{wishlisted: boolean, items: Array}>}
     */
    async toggleWishlist(productId) {
        const current = (await this.getWishlist()) || [];
        const alreadyThere = current.some(
            (entry) => entry.product_id === productId,
        );

        if (alreadyThere) {
            await this.removeFromWishlist(productId);
        } else {
            await this.addToWishlist(productId);
        }

        const items = (await this.getWishlist()) || [];

        return { wishlisted: !alreadyThere, items };
    },
};

export default wishlistService;
