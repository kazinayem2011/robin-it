import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const couponService = {
    /**
     * Validate a promo code against the customer's server-side cart.
     * Throws an ApiError carrying a customer-facing message when the code is rejected.
     */
    async applyCoupon(code) {
        const response = await axiosInstance.post(API_ENDPOINTS.COUPONS.APPLY, {
            code,
        });
        return response?.data || response;
    },
};

export default couponService;
