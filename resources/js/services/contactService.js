import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const contactService = {
    /**
     * Send a message from the Contact page.
     * @param {Object} payload name, email, phone, subject, message
     */
    async sendMessage(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.CONTACT,
            payload,
        );
        return response?.data || response;
    },

    /**
     * Join the mailing list.
     * @param {string} email
     * @param {string} source where they signed up, for the admin's list
     */
    async subscribe(email, source = 'footer') {
        const response = await axiosInstance.post(API_ENDPOINTS.SUBSCRIBE, {
            email,
            source,
        });
        return response?.data || response;
    },
};

export default contactService;
