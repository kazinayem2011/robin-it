import axiosInstance from './axiosInstance';
import { API_ENDPOINTS, ROUTES } from '../constants/endpoints';

/**
 * Checkout & Order API Service (SSOT)
 * Endpoints are centrally controlled from constants/endpoints.js
 */
export const checkoutService = {
    /**
     * Submit checkout form to place an order.
     * @param {Object} orderData - { name, phone, street_address, city, zone, payment_method }
     */
    async processCheckout(orderData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.CHECKOUT.PROCESS,
            orderData,
        );
        // axiosInstance already unwraps to the response envelope, so the payload
        // is response.data. Reading response.data.data went one level too deep
        // and always fell through to the empty fallback.
        return response?.data || response;
    },

    /**
     * Sign in without leaving checkout, with an email or mobile and its
     * password. A web route, like the code requests: it is a step in a
     * browser flow and rides on the session and its CSRF cookie.
     */
    async signIn(login, password) {
        const response = await axiosInstance.post(
            ROUTES.CHECKOUT_SIGN_IN,
            { login, password },
            { baseURL: '' },
        );
        return response?.data || response;
    },
};

export default checkoutService;
