import { describe, expect, it, vi } from 'vitest';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => children,
}));

import { replyGoesTo } from '../Messages';

/*
 * The reply box said "This is emailed to null." for anyone who wrote in
 * without an email address.
 */
describe('replyGoesTo', () => {
    it('names the address when there is one', () => {
        expect(replyGoesTo({ name: 'Rahim', email: 'r@x.com' })).toBe(
            'Reply to Rahim. This is emailed to r@x.com.',
        );
    });

    it('points to their messages for a customer with no address', () => {
        expect(replyGoesTo({ name: 'Rahim', email: null, user_id: 4 })).toMatch(
            /see it in their messages/,
        );
        expect(
            replyGoesTo({
                name: 'Rahim',
                email: null,
                sender: { signed_in: true },
            }),
        ).toMatch(/see it in their messages/);
    });

    it('gives the number for a guest who left only that', () => {
        expect(
            replyGoesTo({ name: 'Rahim', email: null, phone: '01711223344' }),
        ).toBe('Reply to Rahim. They left only a number: 01711223344.');
    });

    it('never says null', () => {
        expect(replyGoesTo({ name: 'Rahim', email: null })).not.toMatch(/null/);
    });
});
