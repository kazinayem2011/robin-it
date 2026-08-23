import axiosInstance from './axiosInstance';

export const storeService = {
    async getStores() {
        const response = await axiosInstance.get('/stores');
        // axiosInstance already unwraps to the response envelope, so the payload
        // is response.data. Reading response.data.data went one level too deep
        // and always fell through to the empty fallback.
        return response?.data || [];
    },
};

export default storeService;
