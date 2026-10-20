import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getStores = vi.fn();
const mapProps = vi.fn();

vi.mock('../../../services', () => ({
    storeService: { getStores: (...a) => getStores(...a) },
}));
vi.mock('../../../Layouts/MainLayout', () => ({ mainLayout: (page) => page }));
vi.mock('../../../Components/SEOHead', () => ({ default: () => null }));
vi.mock('../../../Components/BranchMap', () => ({
    default: (props) => {
        mapProps(props);
        return <div data-testid="branch-map" />;
    },
}));

const { default: StoresIndex } = await import('../Index');

const branch = (over) => ({
    id: 1,
    name: 'Head Office',
    branch_type: 'Showroom and Special Service Centre',
    city: 'Dhaka',
    address: '50 Ruhama Tower, Purana Paltan',
    phone: '01730752475',
    opening_hours: '10 AM – 9 PM',
    latitude: null,
    longitude: null,
    ...over,
});

beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollIntoView = vi.fn();
});

/* One map of every branch above the cards; a branch's name points it there. */
describe('Showrooms', () => {
    it('puts every branch on the one map, with no map inside the cards', async () => {
        getStores.mockResolvedValue([
            branch({ latitude: 23.7339, longitude: 90.4117 }),
            branch({ id: 2, name: 'Khulna Branch', city: 'Khulna' }),
        ]);
        render(<StoresIndex />);

        expect(await screen.findByTestId('branch-map')).toBeInTheDocument();
        expect(mapProps.mock.calls.at(-1)[0].stores).toHaveLength(2);
        expect(document.querySelector('.store-card iframe')).toBeNull();
        expect(screen.getAllByText('Get Directions')).toHaveLength(2);
    });

    it('points the map at a branch when its name is tapped', async () => {
        getStores.mockResolvedValue([
            branch({ latitude: 23.7339, longitude: 90.4117 }),
            branch({ id: 2, name: 'Khulna Branch', city: 'Khulna' }),
        ]);
        render(<StoresIndex />);

        fireEvent.click(
            await screen.findByRole('button', { name: 'Head Office' }),
        );
        expect(mapProps.mock.calls.at(-1)[0].focus).toMatchObject({ id: 1 });

        // Without a pin there is nothing to point at: a plain name.
        expect(
            screen.queryByRole('button', { name: 'Khulna Branch' }),
        ).toBeNull();
    });
});
