import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * The bell's rows, and what can be done to them.
 *
 * The bell itself only ever read them and marked one read. Everything below
 * exists because the notifications page needs to do more than look: mark one
 * back to unread, throw one away, and clear the ones already dealt with.
 *
 * Every call is scoped to the signed-in user by the relation on the server, so
 * an id here can never name somebody else's notification.
 */
export const notificationService = {
    async list() {
        const response = await axiosInstance.get(API_ENDPOINTS.NOTIFICATIONS);
        return response?.data ?? response;
    },

    async markRead(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.NOTIFICATION_READ(id),
        );
        return response?.data ?? response;
    },

    async markUnread(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.NOTIFICATION_UNREAD(id),
        );
        return response?.data ?? response;
    },

    async markAllRead() {
        const response = await axiosInstance.post(
            API_ENDPOINTS.NOTIFICATIONS_READ_ALL,
        );
        return response?.data ?? response;
    },

    async remove(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.NOTIFICATION_DELETE(id),
        );
        return response?.data ?? response;
    },

    /** Only the ones already read — never the unread ones. */
    async clearRead() {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.NOTIFICATIONS_CLEAR_READ,
        );
        return response?.data ?? response;
    },
};

export default notificationService;
