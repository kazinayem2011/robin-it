import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const blogService = {
    /**
     * Get published blog posts / articles (SSOT)
     */
    async getBlogs(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.BLOGS.LIST, {
            params,
        });
        // axiosInstance already unwraps to the response envelope, so the payload
        // is response.data. Reading response.data.data went one level too deep
        // and always fell through to the empty fallback.
        return response?.data || [];
    },

    /**
     * Get single article by slug (SSOT)
     */
    async getBlogBySlug(slug) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.BLOGS.DETAIL(slug),
        );
        return response?.data || null;
    },
};

export default blogService;
