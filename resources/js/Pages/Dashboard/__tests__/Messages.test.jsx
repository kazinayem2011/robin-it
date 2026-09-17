import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const router = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
}));
vi.mock('@/Layouts/MainLayout', () => ({ mainLayout: (page) => page }));
vi.mock('../AccountLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

import Messages from '../Messages';

const thread = {
    id: 7,
    subject: 'Is the RTX 4060 in stock?',
    message: 'Asking about the Gigabyte one.',
    status: 'open',
    status_label: 'In progress',
    created_at: '2026-09-17T09:00:00.000000Z',
    closed_at: null,
    replies: [
        {
            id: 3,
            author_name: 'Nazmul',
            from_customer: false,
            body: 'Yes, three in Uttara.',
            created_at: '2026-09-17T10:00:00.000000Z',
        },
    ],
};

beforeEach(() => router.post.mockReset());

describe('The customer messages page', () => {
    it('shows what was asked and what the shop said', () => {
        render(<Messages user={{}} navCounts={{}} threads={[thread]} />);

        expect(
            screen.getByText('Is the RTX 4060 in stock?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Asking about the Gigabyte one.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Yes, three in Uttara.')).toBeInTheDocument();
        expect(screen.getByText('In progress')).toBeInTheDocument();
        expect(screen.getByText(/Nazmul ·/)).toBeInTheDocument();
    });

    /* The reply box is the half that did not exist: answers came by email, and
       an email reply lands in a mailbox the inbox screen never shows. */
    it('sends a reply on the thread', async () => {
        render(<Messages user={{}} navCounts={{}} threads={[thread]} />);

        await userEvent.type(
            screen.getByLabelText('Write back'),
            'Please hold one for me.',
        );
        await userEvent.click(screen.getByRole('button', { name: /Send/ }));

        await waitFor(() => expect(router.post).toHaveBeenCalledTimes(1));
        expect(router.post.mock.calls[0][0]).toBe(
            '/account/messages/7/replies',
        );
        expect(router.post.mock.calls[0][1]).toEqual({
            body: 'Please hold one for me.',
        });
    });

    it('will not send an empty reply', async () => {
        render(<Messages user={{}} navCounts={{}} threads={[thread]} />);

        expect(screen.getByRole('button', { name: /Send/ })).toBeDisabled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('offers the contact page when there is nothing yet', () => {
        render(<Messages user={{}} navCounts={{}} threads={[]} />);

        expect(screen.getByText('No messages yet')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Write to us' }),
        ).toHaveAttribute('href', '/contact');
    });
});
