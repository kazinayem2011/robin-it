import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const reload = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();
const post = vi.fn();
const patch = vi.fn();
const del = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { reload, get: vi.fn() },
    Head: () => null,
    Link: ({ children }) => <span>{children}</span>,
    usePage: () => ({ props: {}, url: '/admin/attributes' }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

// The picker fetches as you type and is covered by its own tests; what matters
// here is only that what it hands back reaches the payload.
vi.mock('@/Components/CategoryPicker', () => ({
    default: ({ chips = [], onChange, onRemove }) => (
        <div>
            <button type="button" onClick={() => onChange({ id: 7, name: 'Router' })}>
                pick-router
            </button>
            {chips.map((c) => (
                <button key={c.id} type="button" onClick={() => onRemove(c.id)}>
                    unpick-{c.name}
                </button>
            ))}
        </div>
    ),
}));

vi.mock('@/Components/Toast', () => ({
    toast: { error: toastError, success: toastSuccess },
}));

vi.mock('@/services/axiosInstance', () => ({
    default: { post, patch, delete: del },
}));

const { default: Attributes } = await import('../Attributes');

/**
 * The screen that did not exist.
 *
 * The sixty-six filters were seeded, and nothing in the application could add a
 * sixty-seventh — so a new category could be given products, photos and a full
 * spec sheet and still be unfilterable.
 *
 * The delicate part is not creating one. An answer and a product's tick are
 * joined by a cascading foreign key, so an answer that products already carry
 * must not be removable from the list by hand.
 */
describe('Filters screen', () => {
    const enumFilter = {
        id: 1,
        name: 'Wi-Fi Standard',
        slug: 'wi-fi-standard',
        unit: null,
        input_type: 'enum',
        sort_order: 0,
        categories: [{ id: 7, name: 'Router' }],
        values: [
            { id: 11, label: 'Wi-Fi 5', products_count: 4 },
            { id: 12, label: 'Wi-Fi 6', products_count: 0 },
        ],
    };

    beforeEach(() => {
        vi.clearAllMocks();
        post.mockResolvedValue({});
        patch.mockResolvedValue({});
        del.mockResolvedValue({});
    });

    const open = async (user, label) => {
        await user.click(screen.getByRole('button', { name: label }));
    };

    it('names a filter that is on no shelf, because it is never offered', () => {
        render(
            <Attributes
                attributes={[
                    { ...enumFilter, categories: [] },
                    enumFilter,
                ]}
                counts={{ total: 2, values: 4, unattached: 1 }}
            />,
        );

        expect(screen.getByText(/No shelf — never shown/i)).toBeInTheDocument();
    });

    it('offers no way to remove an answer products already carry', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await open(user, /edit/i);

        // Wi-Fi 5 has four products behind it; Wi-Fi 6 has none.
        expect(screen.getByText('4 tagged')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /remove answer 2/i }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /remove answer 1/i }),
        ).not.toBeInTheDocument();
    });

    it('sends the answers in the order they appear, keeping their ids', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await open(user, /edit/i);
        await user.click(screen.getByRole('button', { name: /save filter/i }));

        await waitFor(() => expect(patch).toHaveBeenCalled());

        const [, payload] = patch.mock.calls[0];

        expect(payload.values).toEqual([
            expect.objectContaining({ id: 11, label: 'Wi-Fi 5', sort_order: 0 }),
            expect.objectContaining({ id: 12, label: 'Wi-Fi 6', sort_order: 1 }),
        ]);
        expect(payload.category_ids).toEqual([7]);
    });

    /**
     * A bound belongs to a measurement. Sending one on a list of names is
     * refused server-side, so the form must not offer the boxes at all.
     */
    it('shows band bounds only for a number filter', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[]} counts={{}} />);

        await open(user, /add filter/i);
        expect(screen.queryByLabelText(/^From$/i)).not.toBeInTheDocument();

        await user.selectOptions(
            screen.getByLabelText(/answer type/i),
            'number',
        );

        expect(screen.getByLabelText(/^From$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^To$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^Unit$/i)).toBeInTheDocument();
    });

    /** Switching away has to drop them too, or a stale bound rides along. */
    it('sends no bounds once the type is switched back off a number', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[]} counts={{}} />);

        await open(user, /add filter/i);
        await user.type(screen.getByLabelText(/question/i), 'Panel Type');

        await user.selectOptions(
            screen.getByLabelText(/answer type/i),
            'number',
        );
        await user.type(screen.getByLabelText(/^From$/i), '301');
        await user.type(screen.getByLabelText(/^Unit$/i), 'Mbps');

        await user.selectOptions(screen.getByLabelText(/answer type/i), 'enum');
        await user.click(screen.getByRole('button', { name: /create filter/i }));

        await waitFor(() => expect(post).toHaveBeenCalled());

        const [, payload] = post.mock.calls[0];

        expect(payload.unit).toBeNull();
        expect(payload.values[0].range_from).toBeNull();
        expect(payload.values[0].range_to).toBeNull();
    });

    it('puts the server complaint beside the row it names', async () => {
        const user = userEvent.setup();
        post.mockRejectedValue({
            message: 'That answer is already on the list above.',
            errors: {
                'values.0.label': ['This answer is already on the list above.'],
            },
        });

        render(<Attributes attributes={[]} counts={{}} />);

        await open(user, /add filter/i);
        await user.type(screen.getByLabelText(/question/i), 'Band');
        await user.click(screen.getByRole('button', { name: /create filter/i }));

        await waitFor(() =>
            expect(
                screen.getByText(/already on the list above/i),
            ).toBeInTheDocument(),
        );
        expect(toastError).toHaveBeenCalled();
    });

    it('carries a picked shelf into the payload and lets it be taken back out', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[]} counts={{}} />);

        await open(user, /add filter/i);
        await user.type(screen.getByLabelText(/question/i), 'Band');

        await user.click(screen.getByRole('button', { name: 'pick-router' }));
        await user.click(screen.getByRole('button', { name: 'unpick-Router' }));
        await user.click(screen.getByRole('button', { name: 'pick-router' }));

        await user.click(screen.getByRole('button', { name: /create filter/i }));
        await waitFor(() => expect(post).toHaveBeenCalled());

        // Picked twice with a removal between: still one shelf, not two.
        expect(post.mock.calls[0][1].category_ids).toEqual([7]);
    });

    it('warns before deleting a filter that products answer', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(screen.getByRole('button', { name: /delete/i }));

        expect(
            screen.getByText(/4 product\(s\) answer this filter/i),
        ).toBeInTheDocument();
    });
});
