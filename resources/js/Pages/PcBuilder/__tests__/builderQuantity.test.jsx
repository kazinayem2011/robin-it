import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, beforeEach } from 'vitest';
import useAppStore from '@/store/useAppStore';
import { PcBuilderQuotationModal } from '@/Components/PcBuilderQuotationModal';

/*
 * More than one of a part: two RAM sticks, a second drive.
 */
describe('PC Builder quantity', () => {
    beforeEach(() => useAppStore.getState().clearPcBuilder());

    it('keeps a quantity per part, never below one', () => {
        const { setPcBuilderItem, setPcBuilderQuantity } =
            useAppStore.getState();
        setPcBuilderItem('component-ram-desktop', { id: 7, name: 'RAM' });

        expect(useAppStore.getState().pcBuilderItems[0].quantity).toBe(1);

        setPcBuilderQuantity('component-ram-desktop', 2);
        expect(useAppStore.getState().pcBuilderItems[0].quantity).toBe(2);

        setPcBuilderQuantity('component-ram-desktop', 0);
        expect(useAppStore.getState().pcBuilderItems[0].quantity).toBe(1);
    });

    it('restores the quantity a saved build carried', () => {
        useAppStore
            .getState()
            .setPcBuilderItem('component-ssd', { id: 9, name: 'SSD' }, 3);

        expect(useAppStore.getState().pcBuilderItems[0].quantity).toBe(3);
    });

    it('prints "2 ×" and the line total on the quotation', () => {
        render(
            <PcBuilderQuotationModal
                isOpen
                onClose={() => {}}
                components={[
                    {
                        componentId: 'component-ram-desktop',
                        category_name: 'RAM',
                        quantity: 2,
                        // As the builder sends it: text to show, and the number.
                        product: {
                            id: 7,
                            name: 'Corsair 16GB',
                            price: '৳5,000',
                            raw_price: 5000,
                        },
                    },
                ]}
                totalPrice={10000}
            />,
        );

        expect(screen.getByText(/2 × Corsair 16GB/)).toBeInTheDocument();
        expect(
            document.querySelector('.quotation-price-cell'),
        ).toHaveTextContent('10,000');
    });
});
