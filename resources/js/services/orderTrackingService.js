import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const orderTrackingService = {
    /**
     * Track live order status by order number and phone.
     *
     * @param key The key from the link in the order's own messages, which
     *            opens the order in place of the phone number.
     */
    trackOrder: async (orderNumber, phone, key = null) => {
        const response = await axiosInstance.post(API_ENDPOINTS.ORDERS.TRACK, {
            order_number: orderNumber,
            phone: phone || null,
            ...(key ? { key } : {}),
        });
        return response?.data || response;
    },
};

export default orderTrackingService;
