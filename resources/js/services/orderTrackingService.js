import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const orderTrackingService = {
    /**
     * Track live order status by order number and phone.
     */
    trackOrder: async (orderNumber, phone) => {
        const response = await axiosInstance.post(API_ENDPOINTS.ORDERS.TRACK, {
            order_number: orderNumber,
            phone: phone,
        });
        return response?.data || response;
    },
};

export default orderTrackingService;
