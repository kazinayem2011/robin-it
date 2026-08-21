import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Category API Service (SSOT)
 * Endpoints are centrally controlled from constants/endpoints.js
 */
export const categoryService = {
    /**
     * Get mega menu 3-level category hierarchy.
     */
    async getMegaMenu() {
        const response = await axiosInstance.get(
            API_ENDPOINTS.CATEGORIES.MEGA_MENU,
        );
        return response?.data || response || [];
    },

    /**
     * Get featured bubble categories.
     */
    async getFeaturedCategories() {
        const response = await axiosInstance.get(
            API_ENDPOINTS.CATEGORIES.FEATURED,
        );
        return response?.data || response || [];
    },
};

export default categoryService;
