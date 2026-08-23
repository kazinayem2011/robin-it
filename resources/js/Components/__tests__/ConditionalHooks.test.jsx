import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import ProductCard from '../ProductCard';
import QuickViewModal from '../QuickViewModal';

// These components reach for Inertia and the cart at module scope; none of that
// is what is under test here, so it is stubbed out.
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: null }, site_settings: {} } }),
}));

vi.mock('../../services', () => ({
    cartService: { addToCart: vi.fn().mockResolvedValue({}) },
    wishlistService: { toggle: vi.fn().mockResolvedValue({}) },
    compareService: { add: vi.fn() },
    productService: {},
    reviewService: {},
}));

vi.mock('../../store/useAppStore', () => ({
    default: Object.assign(() => ({}), {
        getState: () => ({ fetchCartCount: vi.fn() }),
    }),
}));

/**
 * Both components used to early-return before calling useState:
 *
 *     if (!product) return null;
 *     const [x, setX] = useState(false);
 *
 * React matches hooks up by call order, so the render where product arrives
 * calls one more hook than the render before it and React throws
 * "Rendered more hooks than during the previous render". A product list that
 * mounts empty and then fills — which is every async list in this app — hits
 * exactly that sequence.
 */
describe('components that render before their data arrives', () => {
    const product = {
        id: 1,
        name: 'RTX 4070 Super',
        slug: 'rtx-4070-super',
        price: 82000,
        discount_price: null,
        stock_quantity: 5,
        in_stock: true,
        images: [],
    };

    it('ProductCard survives mounting without a product and then getting one', () => {
        const { rerender } = render(<ProductCard product={null} />);

        expect(() => rerender(<ProductCard product={product} />)).not.toThrow();
    });

    it('ProductCard survives losing its product again', () => {
        const { rerender } = render(<ProductCard product={product} />);

        expect(() => rerender(<ProductCard product={null} />)).not.toThrow();
    });

    /** The modal is mounted with product=null until a card is clicked. */
    it('QuickViewModal survives being mounted empty and then filled', () => {
        const { rerender } = render(
            <QuickViewModal show={false} onClose={() => {}} product={null} />,
        );

        expect(() =>
            rerender(
                <QuickViewModal show onClose={() => {}} product={product} />,
            ),
        ).not.toThrow();
    });

    it('QuickViewModal survives being emptied again on close', () => {
        const { rerender } = render(
            <QuickViewModal show onClose={() => {}} product={product} />,
        );

        expect(() =>
            rerender(
                <QuickViewModal
                    show={false}
                    onClose={() => {}}
                    product={null}
                />,
            ),
        ).not.toThrow();
    });
});
