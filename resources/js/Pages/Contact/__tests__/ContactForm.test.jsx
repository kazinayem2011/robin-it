import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

let pageProps = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    usePage: () => ({ props: pageProps, url: '/contact' }),
}));
vi.mock('../../../Layouts/MainLayout', () => ({ mainLayout: (p) => p }));
vi.mock('../../../Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const sendMessage = vi.fn().mockResolvedValue({ message: 'Thanks.' });

vi.mock('../../../services', () => ({
    contactService: { sendMessage: (...a) => sendMessage(...a) },
}));

import Contact from '../Index';
import {
    SUPPORT_SERVICES,
    serviceFromQuery,
} from '../../../constants/supportServices';

/*
 * StarTech's service desk: the service first, then the problem, then who to
 * answer. The service is sent as the subject, which the inbox already reads.
 */
describe('Contact form', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        pageProps = {};
        window.history.replaceState(null, '', '/contact');
    });

    it('asks for the service first, then the problem, then who to answer', () => {
        const { container } = render(<Contact />);

        const labels = [
            ...container.querySelectorAll('form label, form [role=combobox]'),
        ]
            .map((el) => el.textContent.replace('*', '').trim())
            .filter(Boolean);

        expect(labels[0]).toMatch(/what do you need help with/i);
        expect(labels.join(' | ')).toMatch(
            /tell us about the problem.*name.*phone.*email/i,
        );
        expect(
            screen.getByRole('button', { name: /request support/i }),
        ).toBeInTheDocument();
    });

    it('offers repairs and the questions a shop gets, in its own words', async () => {
        const person = userEvent.setup();
        render(<Contact />);

        await person.click(screen.getByRole('combobox'));

        expect(
            screen.getByRole('option', { name: 'Laptop repair or servicing' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Warranty claim' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Something else' }),
        ).toBeInTheDocument();
    });

    it('sends the chosen service as the subject', async () => {
        const person = userEvent.setup();
        render(<Contact />);

        await person.click(screen.getByRole('combobox'));
        await person.click(
            screen.getByRole('option', { name: 'Data recovery' }),
        );
        await person.type(
            screen.getByLabelText(/tell us about the problem/i),
            'The drive clicks and will not mount.',
        );
        await person.type(screen.getByLabelText(/^name/i), 'Rahim');
        await person.type(screen.getByLabelText(/^phone/i), '01711223344');
        await person.click(
            screen.getByRole('button', { name: /request support/i }),
        );

        await waitFor(() => expect(sendMessage).toHaveBeenCalled());
        expect(sendMessage.mock.calls[0][0]).toMatchObject({
            subject: 'Data recovery',
            message: 'The drive clicks and will not mount.',
            phone: '01711223344',
        });
    });

    it('will not send without a service', async () => {
        const person = userEvent.setup();
        render(<Contact />);

        await person.click(
            screen.getByRole('button', { name: /request support/i }),
        );

        expect(
            await screen.findByText(/choose what you need help with/i),
        ).toBeInTheDocument();
        expect(sendMessage).not.toHaveBeenCalled();
    });

    it('asks a guest for a phone number; the email is optional', async () => {
        const person = userEvent.setup();
        render(<Contact />);

        expect(screen.getByText(/email \(optional\)/i)).toBeInTheDocument();

        await person.click(screen.getByRole('combobox'));
        await person.click(screen.getByRole('option', { name: 'TV repair' }));
        await person.type(
            screen.getByLabelText(/tell us about the problem/i),
            'No picture, but there is sound.',
        );
        await person.type(screen.getByLabelText(/^name/i), 'Rahim');
        await person.type(screen.getByLabelText(/email/i), 'rahim@example.com');
        await person.click(
            screen.getByRole('button', { name: /request support/i }),
        );

        expect(
            await screen.findByText(/leave us a mobile number/i),
        ).toBeInTheDocument();
        expect(sendMessage).not.toHaveBeenCalled();
    });

    it('asks a signed-in customer for neither', () => {
        pageProps = { auth: { user: { id: 1 } } };
        render(<Contact contact={{ name: 'Rahim', email: '', phone: '' }} />);

        expect(screen.getByText(/phone \(optional\)/i)).toBeInTheDocument();
    });

    it('opens on a service named in the address', () => {
        window.history.replaceState(
            null,
            '',
            '/contact?service=warranty-claim',
        );
        render(<Contact />);

        expect(screen.getByRole('combobox')).toHaveTextContent(
            'Warranty claim',
        );
    });
});

describe('serviceFromQuery', () => {
    it('matches a slug or the words, and nothing else', () => {
        expect(serviceFromQuery('?service=data-recovery')).toBe(
            'Data recovery',
        );
        expect(serviceFromQuery('?service=Warranty%20claim')).toBe(
            'Warranty claim',
        );
        expect(serviceFromQuery('?service=teleportation')).toBe('');
        expect(serviceFromQuery('')).toBe('');
    });

    it('has no two services that read the same', () => {
        expect(new Set(SUPPORT_SERVICES).size).toBe(SUPPORT_SERVICES.length);
    });
});
