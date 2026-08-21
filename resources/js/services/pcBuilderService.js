import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const pcBuilderService = {
    /**
     * Get PC Builder blueprint categories.
     */
    getCategories: async () => {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PC_BUILDER.CATEGORIES,
        );
        return response?.data || response;
    },

    /**
     * Get selectable components for a slot.
     *
     * `selection` is the current build as { slot: productId }; the API uses it to
     * mark each candidate compatible / incompatible / unverified.
     */
    getComponents: async (categorySlug, search = '', selection = {}) => {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PC_BUILDER.COMPONENTS(categorySlug),
            {
                params: { search, selection },
            },
        );
        return response?.data || response;
    },

    /**
     * Check the current build for compatibility conflicts.
     * @param {Object} selection - { slot: productId }
     */
    checkCompatibility: async (selection) => {
        const response = await axiosInstance.post(
            API_ENDPOINTS.PC_BUILDER.CHECK,
            { selection },
        );
        return response?.data || response;
    },

    /**
     * Save a custom PC build and generate a shareable URL.
     */
    saveBuild: async (buildData) => {
        const response = await axiosInstance.post(
            API_ENDPOINTS.PC_BUILDER.SAVE,
            buildData,
        );
        return response?.data || response;
    },

    /**
     * Load a saved PC build configuration by share code.
     */
    loadBuild: async (shareCode) => {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PC_BUILDER.LOAD(shareCode),
        );
        return response?.data || response;
    },
};

export default pcBuilderService;
