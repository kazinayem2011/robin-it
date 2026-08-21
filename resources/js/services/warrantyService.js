import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const warrantyService = {
    /**
     * Check warranty or RMA status by Serial Number or Claim ID.
     * @param {string} query
     */
    async checkWarranty(query) {
        const response = await axiosInstance.get(API_ENDPOINTS.WARRANTY.CHECK, {
            params: { query },
        });
        return response?.data || response;
    },

    /**
     * Submit an RMA / Warranty claim request.
     * @param {Object} claimData
     */
    async submitClaim(claimData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.WARRANTY.CLAIM,
            claimData,
        );
        return response?.data || response;
    },
};

export default warrantyService;
