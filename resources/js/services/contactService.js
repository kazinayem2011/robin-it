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
        // The whole reply: its message says where the answer will turn up.
        return response;
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
        // The whole reply: its message says where the answer will turn up.
        return response;
    },
};

export default contactService;
