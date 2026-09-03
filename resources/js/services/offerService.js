import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * The campaigns the shop is running.
 *
 * Not the discounted listing — that is the product listing with `onSaleOnly`,
 * worked out from prices, and it lives at /discounts.
 */
export const offerService = {
    /** What is on, and what is coming. */
    async getOffers() {
        const response = await axiosInstance.get(API_ENDPOINTS.OFFERS.LIST);

        return response?.data || [];
    },

    /** One offer by slug. Answers for finished offers too, so a link already
     *  sent to a customer still explains what it was. */
    async getOfferBySlug(slug) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.OFFERS.DETAIL(slug),
        );

        return response?.data || null;
    },
};

export default offerService;
