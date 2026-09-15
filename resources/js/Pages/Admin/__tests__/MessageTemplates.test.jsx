import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/templates' }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('@/Components/RichTextEditor', () => ({
    default: ({ value, onChange }) => (
        <textarea
            aria-label="Body"
            value={value}
            onChange={(e) => onChange(e.target.value)}
        />
    ),
}));

const sendTemplateTest = vi.fn().mockResolvedValue({ message: 'Test sent.' });

vi.mock('@/services', () => ({
    adminService: {
        updateTemplate: vi.fn().mockResolvedValue({}),
        previewTemplate: vi.fn().mockResolvedValue({ data: {} }),
        sendTemplateTest: (...args) => sendTemplateTest(...args),
    },
}));

import MessageTemplates from '../MessageTemplates';

const emailTemplate = {
    id: 7,
    key: 'welcome',
    name: 'Welcome',
    group: 'Account',
    subject: 'Welcome to {shop_name}',
    body: '<p>Hi {customer_name},</p>',
    variables: ['shop_name', 'customer_name'],
};

const smsTemplate = (body) => ({
    id: 3,
    key: 'order_placed',
    name: 'Order received',
    group: 'Orders',
    body,
    variables: ['order_number'],
    parts: 1,
});

/** The page opens on Email; the SMS list is behind its tab. */
const toSms = async () => {
    await userEvent.click(screen.getByRole('tab', { name: /^SMS/ }));
};

/** Open the one template on screen for editing. */
const openEditor = async (name) => {
    await userEvent.click(screen.getByRole('button', { name: `Edit ${name}` }));
};

beforeEach(() => {
    sendTemplateTest.mockClear();
});

describe('Message templates', () => {
    /**
     * The row that broke. A field with no label, wedged between three buttons
     * inside a modal that stayed at its default width, squeezed "Send test"
     * onto two lines and floated the box above everything beside it. The label
     * is what a reader of the screen has to be given; the disabled button is
     * what tells them the field is why nothing happens.
     */
    it('labels the test field and keeps the button off until it is filled', async () => {
        render(
            <MessageTemplates
                emailTemplates={[emailTemplate]}
                smsTemplates={[]}
                samples={{ shop_name: 'Robins', customer_name: 'Rahim' }}
            />,
        );

        await openEditor('Welcome');

        const field = screen.getByLabelText('Send a test email to');
        const button = screen.getByRole('button', { name: /send test/i });

        expect(button).toBeDisabled();

        await userEvent.type(field, 'someone@example.com');

        expect(button).toBeEnabled();
        await userEvent.click(button);

        await waitFor(() =>
            expect(sendTemplateTest).toHaveBeenCalledWith(
                'email',
                7,
                'someone@example.com',
            ),
        );
    });

    /**
     * The gateway charges by the part, and a written `{order_number}` is
     * fourteen characters where the number itself is five — so counting the
     * template as typed overstates the cost of every message the shop sends.
     */
    it('counts what will be sent, not the placeholder standing in for it', async () => {
        render(
            <MessageTemplates
                emailTemplates={[]}
                smsTemplates={[smsTemplate('{order_number}')]}
                samples={{ order_number: 'ORD-1' }}
            />,
        );

        await toSms();
        await openEditor('Order received');

        expect(screen.getByText(/5 characters/)).toBeInTheDocument();
        expect(screen.getByText(/160 characters per part/)).toBeInTheDocument();
    });

    /**
     * One Bengali letter moves the whole message onto the gateway's 70
     * character alphabet — and these messages are all written in Bengali, so a
     * meter that assumed 160 would be wrong about every one of them.
     */
    it('drops to the Bengali allowance as soon as one Bengali letter appears', async () => {
        render(
            <MessageTemplates
                emailTemplates={[]}
                smsTemplates={[smsTemplate('অ'.repeat(71))]}
                samples={{}}
            />,
        );

        await toSms();
        await openEditor('Order received');

        expect(screen.getByText(/70 characters per part/)).toBeInTheDocument();
        /* 71 characters is past the 70 a single part holds. */
        expect(screen.getByText('2 parts')).toBeInTheDocument();
    });

    /** Switched off, nothing it says is reaching anyone — say so. */
    it('says so when SMS is switched off', async () => {
        render(
            <MessageTemplates
                emailTemplates={[]}
                smsTemplates={[smsTemplate('hello')]}
                samples={{}}
                smsEnabled={false}
            />,
        );

        await toSms();

        expect(screen.getByText(/SMS is switched off/)).toBeInTheDocument();
    });
});
