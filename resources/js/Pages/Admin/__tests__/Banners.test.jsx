import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/banners' }),
}));

vi.mock('../../../Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('../../../Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('../../../Components/ImageCropperModal', () => ({
    default: () => null,
}));

const createBanner = vi.fn().mockResolvedValue({});

vi.mock('../../../services', () => ({
    adminService: {
        createBanner: (...a) => createBanner(...a),
        updateBanner: vi.fn().mockResolvedValue({}),
        deleteBanner: vi.fn().mockResolvedValue({}),
    },
    uploadService: { uploadImage: vi.fn() },
}));

import AdminBanners from '../Banners';

const banners = [
    {
        id: 1,
        title: 'ROG Strix',
        position: 'hero',
        sort_order: 2,
        is_active: true,
    },
    {
        id: 2,
        title: 'Aurora X9',
        position: 'hero',
        sort_order: 1,
        is_active: false,
    },
    {
        id: 3,
        title: 'Build Your Dream PC',
        position: 'promo_side',
        sort_order: 1,
        is_active: true,
    },
    {
        id: 4,
        title: 'Old top bar',
        position: 'promo_top',
        sort_order: 2,
        is_active: true,
    },
];

/*
 * Hero slides and promo cards were one grid told apart by a small tag, with
 * two placements on offer that nothing on the site showed.
 */
describe('Admin banners', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.history.replaceState(null, '', '/admin/banners');
    });

    it('lists hero slides on their own, in display order', () => {
        render(<AdminBanners banners={banners} />);

        const titles = screen
            .getAllByRole('heading', { level: 4 })
            .map((h) => h.textContent);

        expect(titles).toEqual(['Aurora X9', 'ROG Strix']);
        expect(screen.getByText(/1 live, 1 hidden/)).toBeInTheDocument();
        expect(
            screen.queryByText('Build Your Dream PC'),
        ).not.toBeInTheDocument();
    });

    it('lists promo cards on the other tab, old top-bar ones included', async () => {
        const person = userEvent.setup();
        render(<AdminBanners banners={banners} />);

        await person.click(screen.getByRole('tab', { name: /promo cards/i }));

        const titles = screen
            .getAllByRole('heading', { level: 4 })
            .map((h) => h.textContent);

        expect(titles).toEqual(['Build Your Dream PC', 'Old top bar']);
        expect(window.location.search).toBe('?type=promo');
    });

    it('opens on the promo list when the address says so', () => {
        window.history.replaceState(null, '', '/admin/banners?type=promo');
        render(<AdminBanners banners={banners} />);

        expect(screen.getByText('Build Your Dream PC')).toBeInTheDocument();
        expect(screen.queryByText('ROG Strix')).not.toBeInTheDocument();
    });

    it('adds to the list it was opened from, after the last one there', async () => {
        const person = userEvent.setup();
        render(<AdminBanners banners={banners} />);

        await person.click(screen.getByRole('tab', { name: /promo cards/i }));
        await person.click(
            screen.getByRole('button', { name: /add promo card/i }),
        );

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole('combobox')).toHaveTextContent(
            /promo card/i,
        );
        expect(
            within(dialog).getByLabelText(/order among promo cards/i),
        ).toHaveValue(3);
    });

    it('offers only the two places the homepage shows', async () => {
        const person = userEvent.setup();
        render(<AdminBanners banners={banners} />);

        await person.click(
            screen.getByRole('button', { name: /add hero slide/i }),
        );
        await person.click(screen.getByRole('combobox'));

        const options = screen
            .getAllByRole('option')
            .map((o) => o.textContent.trim());

        expect(options).toEqual([
            'Hero slide (1920 × 800 px)',
            'Promo card (800 × 500 px)',
        ]);
    });

    it('says plainly when a list is empty', () => {
        render(
            <AdminBanners
                banners={banners.filter((b) => b.position !== 'hero')}
            />,
        );

        expect(screen.getByText(/no hero slides yet/i)).toBeInTheDocument();
    });
});
