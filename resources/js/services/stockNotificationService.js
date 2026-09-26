import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * "Tell me when this is back in stock."
 */
export const stockNotificationService = {
    /** `contact` is an email address or a mobile number; the server tells which. */
    async subscribe({ product_id, product_variant_id = null, contact }) {
        const response = await axiosInstance.post(API_ENDPOINTS.STOCK_NOTIFY, {
            product_id,
            ...(product_variant_id ? { product_variant_id } : {}),
            contact,
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
