import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

const getStores = vi.fn();

vi.mock('../../../services', () => ({
    storeService: { getStores: (...a) => getStores(...a) },
}));
vi.mock('../../../Layouts/MainLayout', () => ({ mainLayout: (page) => page }));
vi.mock('../../../Components/SEOHead', () => ({ default: () => null }));

const { default: StoresIndex } = await import('../Index');

const branch = (over) => ({
    id: 1,
    name: 'Polton Branch',
    branch_type: 'Showroom and Special Service Centre',
    city: 'Dhaka',
    address: '50 Ruhama mension',
    phone: '01730752475',
    opening_hours: '10 AM – 9 PM',
    ...over,
});

/* The branch's map beside its details, from the link set in the admin. */
describe('Showrooms', () => {
    it('shows a branch on its map, beside its details', async () => {
        getStores.mockResolvedValue([
            branch({
                map_embed_url:
                    'https://maps.google.com/maps?q=23.7%2C90.4&z=17&output=embed',
            }),
        ]);
        render(<StoresIndex />);

        const map = await screen.findByTitle('Map: Polton Branch');
        expect(map.getAttribute('src')).toBe(
            'https://maps.google.com/maps?q=23.7%2C90.4&z=17&output=embed',
        );
        expect(map.closest('.store-card').classList.contains('has-map')).toBe(
            true,
        );
        // Get Directions stays as it was.
        expect(screen.getByText('Get Directions')).toBeInTheDocument();
    });

    it('keeps a branch without a map as it was', async () => {
        getStores.mockResolvedValue([branch({ map_embed_url: null })]);
        render(<StoresIndex />);

        expect(await screen.findByText('Polton Branch')).toBeInTheDocument();
        expect(screen.queryByTitle('Map: Polton Branch')).toBeNull();
    });
});
