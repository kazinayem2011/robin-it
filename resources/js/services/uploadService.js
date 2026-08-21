import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Admin image uploads.
 *
 * The cropper previously handed its result straight into the form's image_path
 * field, which is a VARCHAR(255) column — a base64 data URL could never fit, so
 * images were never really stored. The cropped Blob is posted here instead and
 * the form keeps the returned public path.
 */
export const uploadService = {
    /**
     * @param {File|Blob} file
     * @param {'products'|'banners'|'blogs'|'brands'|'categories'} folder
     * @returns {Promise<{path: string, name: string, size: number}>}
     */
    async uploadImage(file, folder = 'products') {
        const form = new FormData();
        form.append('image', file, file.name || 'upload.jpg');
        form.append('folder', folder);

        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.MEDIA,
            form,
            {
                // Let the browser set the multipart boundary itself.
                headers: { 'Content-Type': undefined },
            },
        );

        return response?.data || response;
    },

    /**
     * Remove a previously uploaded image by its public path.
     */
    async deleteImage(path) {
        const response = await axiosInstance.delete(API_ENDPOINTS.ADMIN.MEDIA, {
            data: { path },
        });
        return response?.data || response;
    },
};

export default uploadService;
