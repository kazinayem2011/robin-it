import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

// A request that never answers: the editor stays in its loading state.
vi.mock('../../../../services/axiosInstance', () => ({
    default: { get: vi.fn(() => new Promise(() => {})) },
}));

import AttributeEditor from '../AttributeEditor';

describe('AttributeEditor while the filters load', () => {
    it('shows a skeleton of the filters, not a sentence', () => {
        const { container } = render(
            <AttributeEditor
                formik={{
                    values: { category_id: 5, attribute_value_ids: [] },
                    setFieldValue: () => {},
                }}
                onCount={() => {}}
            />,
        );

        expect(
            screen.getByLabelText("Loading this category's filters"),
        ).toHaveAttribute('aria-busy', 'true');
        expect(
            container.querySelectorAll('.skeleton-shimmer').length,
        ).toBeGreaterThan(5);
        expect(screen.queryByText(/Loading/)).not.toBeInTheDocument();
    });
});
