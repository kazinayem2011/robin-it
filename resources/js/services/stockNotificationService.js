import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * "Tell me when this is back in stock."
 */
export const stockNotificationService = {
    async subscribe({ product_id, product_variant_id = null, email }) {
        const response = await axiosInstance.post(API_ENDPOINTS.STOCK_NOTIFY, {
            product_id,
            ...(product_variant_id ? { product_variant_id } : {}),
            email,
        });

        return response?.data || null;
    },

    /** How many people are already waiting, so the page can say so. */
    async count({ product_id, product_variant_id = null }) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.STOCK_NOTIFY_COUNT,
            {
                params: {
                    product_id,
                    ...(product_variant_id ? { product_variant_id } : {}),
                },
            },
        );

        return response?.data || null;
    },
};

export default stockNotificationService;
