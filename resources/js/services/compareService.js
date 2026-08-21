import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Compare API Service (SSOT)
 * Endpoints are centrally controlled from constants/endpoints.js
 */
export const compareService = {
    async getComparison() {
        try {
            const response = await axiosInstance.get(
                API_ENDPOINTS.COMPARE.BASE,
            );
            const data = response?.data || response;
            if (Array.isArray(data) && data.length > 0) {
                return data;
            }
        } catch (e) {}

        try {
            const stored = localStorage.getItem('startech_compare_items');
            return stored ? JSON.parse(stored) : [];
        } catch (e) {
            return [];
        }
    },

    async addToCompare(productOrId) {
        return this.addToComparison(productOrId);
    },

    async addToComparison(productOrId) {
        const id =
            typeof productOrId === 'object' && productOrId !== null
                ? productOrId.id
                : productOrId;

        // Check local storage items count
        let stored = [];
        try {
            stored = JSON.parse(
                localStorage.getItem('startech_compare_items') || '[]',
            );
        } catch (e) {
            stored = [];
        }

        const isAlreadyAdded = stored.some((p) => (p.id || p) === id);

        if (!isAlreadyAdded && stored.length >= 4) {
            throw new Error(
                'You can compare a maximum of 4 items at a time. Please remove a product first.',
            );
        }

        if (
            typeof productOrId === 'object' &&
            productOrId !== null &&
            !isAlreadyAdded
        ) {
            stored.push(productOrId);
            localStorage.setItem(
                'startech_compare_items',
                JSON.stringify(stored),
            );
        }

        try {
            const response = await axiosInstance.post(
                API_ENDPOINTS.COMPARE.BASE,
                {
                    product_id: id,
                },
            );
            return response?.data || response;
        } catch (e) {
            if (e.response?.data?.message) {
                throw new Error(e.response.data.message);
            }
            return { error: false, message: 'Added to local comparison' };
        }
    },

    async removeFromCompare(productId) {
        return this.removeFromComparison(productId);
    },

    async removeFromComparison(productId) {
        try {
            const stored = JSON.parse(
                localStorage.getItem('startech_compare_items') || '[]',
            );
            const filtered = stored.filter((p) => p.id !== productId);
            localStorage.setItem(
                'startech_compare_items',
                JSON.stringify(filtered),
            );
        } catch (e) {}

        try {
            const response = await axiosInstance.delete(
                API_ENDPOINTS.COMPARE.ITEM(productId),
            );
            return response?.data || response;
        } catch (e) {
            return { error: false, message: 'Removed from local comparison' };
        }
    },
};

export default compareService;
