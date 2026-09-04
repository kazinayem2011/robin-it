import React from 'react';
import { render } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => <a href={href} {...rest}>{children}</a>,
    router: { visit: vi.fn() },
    usePage: () => ({ url: '/' }),
}));

const { DataTable } = await import('../DataTable');

/**
 * Below 640px the table head is hidden and every row becomes a card, so a
 * cell has to carry its own column name. It reads it from data-label, which
 * is written here — the stylesheet only prints what this puts on the cell.
 *
 * Nothing on screen says when this breaks: the cards keep their shape and
 * quietly lose every label, which on the orders list leaves four unexplained
 * values per card.
 */
describe('DataTable cell labels', () => {
    const rows = [{ id: 1, name: 'Corsair Vengeance', total: '৳10,500' }];

    const renderWith = (columns) =>
        render(<DataTable columns={columns} data={rows} pagination={false} />);

    it('labels each cell with its column header', () => {
        const { container } = renderWith([
            { key: 'name', header: 'Product', render: (r) => r.name },
            { key: 'total', header: 'Total BDT', render: (r) => r.total },
        ]);

        const labels = [...container.querySelectorAll('tbody td')].map((td) =>
            td.getAttribute('data-label'),
        );

        expect(labels).toEqual(['Product', 'Total BDT']);
    });

    it('leaves the actions column unlabelled rather than labelling it ""', () => {
        const { container } = renderWith([
            { key: 'name', header: 'Product', render: (r) => r.name },
            { key: 'actions', header: '', render: () => <button>Edit</button> },
        ]);

        const cells = [...container.querySelectorAll('tbody td')];

        expect(cells[0]).toHaveAttribute('data-label', 'Product');
        // Not an empty label, which would print an empty box before the buttons.
        expect(cells[1]).not.toHaveAttribute('data-label');
    });

    it('skips a header that is not text, having nothing to quote from it', () => {
        const { container } = renderWith([
            { key: 'pick', header: <input type="checkbox" aria-label="Select" />, render: () => 'x' },
            { key: 'blank', header: '   ', render: () => 'y' },
        ]);

        const cells = [...container.querySelectorAll('tbody td')];

        expect(cells[0]).not.toHaveAttribute('data-label');
        expect(cells[1]).not.toHaveAttribute('data-label');
    });
});
