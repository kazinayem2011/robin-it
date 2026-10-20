import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn() },
    usePage: () => ({ props: {} }),
}));

const { default: ProductFilters } = await import('../ProductFilters');

/* The owner asked for the filter list without a count beside each option. */
describe('ProductFilters', () => {
    it('lists options without how many products each leaves', () => {
        const { container } = render(
            <ProductFilters
                facets={{
                    total: 11,
                    attributes: [
                        {
                            slug: 'ram-size',
                            name: 'RAM Size',
                            values: [{ slug: '8-gb', label: '8 GB', count: 8 }],
                        },
                    ],
                }}
                filters={{}}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByText('8 GB')).toBeInTheDocument();
        expect(container.querySelector('.plp-filter-count')).toBeNull();
    });
});
