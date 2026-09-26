import { describe, it, expect, vi } from 'vitest';
import axiosInstance from '../axiosInstance';
import stockNotificationService from '../stockNotificationService';

vi.mock('../axiosInstance', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: { waiting: 1 } })) },
}));

/*
 * The form's tests replace this service, so they could not see it still
 * posting `email`: a number typed into the box arrived at the server as
 * nothing at all, and was refused as empty.
 */
describe('stockNotificationService.subscribe', () => {
    it('posts what was typed as the contact, email or mobile alike', async () => {
        await stockNotificationService.subscribe({
            product_id: 3,
            contact: '01711223344',
        });

        expect(axiosInstance.post).toHaveBeenCalledWith(expect.any(String), {
            product_id: 3,
            contact: '01711223344',
        });
    });
});
