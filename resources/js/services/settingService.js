import axiosInstance from './axiosInstance';

export const settingService = {
    async getSettings() {
        const response = await axiosInstance.get('/settings');
        return response.data?.data || {};
    },
};

export default settingService;
