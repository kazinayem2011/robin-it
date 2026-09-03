import React from 'react';
import { Link } from '@inertiajs/react';
import { Scale, ShoppingCart } from 'lucide-react';
import useAppStore from '../store/useAppStore';
import { ROUTES } from '../constants/endpoints';
import './QuickDock.css';

/**
 * Compare and cart, pinned to the side of the page.
 *
 * Both used to sit in the header's tool row, which on a shop this size is
 * seven controls competing for the same strip — and the two that carry a
 * running count are the two a shopper checks most while scrolling a listing,
 * which is exactly when the header has been scrolled away.
 *
 * Anchored rather than floating loose: it sits on the right edge at the
 * vertical middle, so it is in the same place on every page and never over
 * the content a listing puts in the centre.
 *
 * Kept at every width. Cart is not optional, and hiding this on a phone after
 * taking it out of the header would leave nowhere to reach it from.
 */
export default function QuickDock() {
    const cartCount = useAppStore((state) => state.cartCount);
    const compareCount = useAppStore((state) => state.compareCount);

    const items = [
        {
            href: ROUTES.COMPARE,
            label: 'Compare',
            icon: Scale,
            count: compareCount,
        },
        {
            href: ROUTES.CART,
            label: 'Cart',
            icon: ShoppingCart,
            count: cartCount,
        },
    ];

    return (
        <aside className="quick-dock" aria-label="Compare and cart">
            {items.map(({ href, label, icon: Icon, count }) => (
                <Link
                    key={label}
                    href={href}
                    className="quick-dock-btn"
                    aria-label={count > 0 ? `${label}, ${count} items` : label}
                    title={label}
                >
                    <Icon size={19} />

                    {/* The count is the reason this is worth pinning: a
                        shopper adding a fourth thing to compare wants to see
                        it become four. Zero says nothing, so it is absent
                        rather than shown as a nought. */}
                    {count > 0 && (
                        <span className="quick-dock-count">{count}</span>
                    )}

                    <span className="quick-dock-label">{label}</span>
                </Link>
            ))}
        </aside>
    );
}
