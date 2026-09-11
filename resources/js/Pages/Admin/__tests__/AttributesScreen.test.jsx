import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
            <button
                type="button"
                onClick={() => onChange({ id: 7, name: 'Router' })}
            >
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
                attributes={[{ ...enumFilter, categories: [] }, enumFilter]}
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
            expect.objectContaining({
                id: 11,
                label: 'Wi-Fi 5',
                sort_order: 0,
            }),
            expect.objectContaining({
                id: 12,
                label: 'Wi-Fi 6',
                sort_order: 1,
            }),
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
        await user.click(
            screen.getByRole('button', { name: /create filter/i }),
        );

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
        await user.click(
            screen.getByRole('button', { name: /create filter/i }),
        );

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

        await user.click(
            screen.getByRole('button', { name: /create filter/i }),
        );
        await waitFor(() => expect(post).toHaveBeenCalled());

        // Picked twice with a removal between: still one shelf, not two.
        expect(post.mock.calls[0][1].category_ids).toEqual([7]);
    });

    /**
     * The order here is the order the sidebar draws the checkboxes in, and it
     * is sent as each row's position — so a move only has to rearrange the
     * array, and the save that follows carries it.
     *
     * The grip used to be an icon in a span that did nothing at all: a control
     * that looks draggable and is not.
     */
    it('moves an answer down the list with the keyboard', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^edit wi-fi standard$/i }),
        );

        await user.click(
            screen.getByRole('button', { name: /reorder answer 1/i }),
        );
        await user.keyboard('{ArrowDown}');

        await user.click(screen.getByRole('button', { name: /save filter/i }));
        await waitFor(() => expect(patch).toHaveBeenCalled());

        // Wi-Fi 6 is now first, and each row's position is what is sent.
        expect(patch.mock.calls[0][1].values).toEqual([
            expect.objectContaining({
                id: 12,
                label: 'Wi-Fi 6',
                sort_order: 0,
            }),
            expect.objectContaining({
                id: 11,
                label: 'Wi-Fi 5',
                sort_order: 1,
            }),
        ]);
    });

    it('will not move the first answer above itself', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^edit wi-fi standard$/i }),
        );

        await user.click(
            screen.getByRole('button', { name: /reorder answer 1/i }),
        );
        await user.keyboard('{ArrowUp}');

        await user.click(screen.getByRole('button', { name: /save filter/i }));
        await waitFor(() => expect(patch).toHaveBeenCalled());

        expect(patch.mock.calls[0][1].values[0]).toEqual(
            expect.objectContaining({ id: 11, sort_order: 0 }),
        );
    });

    it('reorders by dragging a row onto another', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <Attributes attributes={[enumFilter]} counts={{}} />,
        );

        await user.click(
            screen.getByRole('button', { name: /^edit wi-fi standard$/i }),
        );

        const rows = container.querySelectorAll('.admin-attr-value-row');
        expect(rows).toHaveLength(2);

        // The row only becomes draggable while the handle is held, so text in
        // the label beside it stays selectable.
        fireEvent.mouseDown(
            screen.getByRole('button', { name: /reorder answer 1/i }),
        );
        expect(rows[0]).toHaveAttribute('draggable', 'true');

        fireEvent.dragStart(rows[0]);
        fireEvent.dragEnter(rows[1]);
        fireEvent.dragEnd(rows[0]);

        await user.click(screen.getByRole('button', { name: /save filter/i }));
        await waitFor(() => expect(patch).toHaveBeenCalled());

        expect(patch.mock.calls[0][1].values.map((v) => v.id)).toEqual([
            12, 11,
        ]);
    });

    /** Dragging from the label would make the text unselectable. */
    it('is not draggable until the handle is held', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <Attributes attributes={[enumFilter]} counts={{}} />,
        );

        await user.click(
            screen.getByRole('button', { name: /^edit wi-fi standard$/i }),
        );

        const row = container.querySelector('.admin-attr-value-row');
        expect(row).toHaveAttribute('draggable', 'false');
    });

    // ── asking the same question elsewhere ───────────────────────────

    /*
     * Twenty-nine of the sixty-six filters here are already a repeat of
     * another by name, so this is nearly half of what the screen is for.
     * Processor Model carries sixteen answers; retyping those to ask the same
     * thing about desktops is the work this removes.
     *
     * It fills the form in rather than writing a row: nothing exists until it
     * is saved, so the shelves are chosen before anything reaches the
     * catalogue rather than corrected afterwards.
     */
    it('fills the form in rather than writing a filter', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );

        expect(
            await screen.findByDisplayValue('Wi-Fi Standard'),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        expect(patch).not.toHaveBeenCalled();
    });

    it('brings every answer across', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );

        expect(await screen.findByDisplayValue('Wi-Fi 5')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Wi-Fi 6')).toBeInTheDocument();
    });

    /*
     * The shelves are the one thing that differs, and a filter on no shelf is
     * offered to nobody — so leaving them is the decision being made, not an
     * oversight to be inherited.
     */
    it('leaves the shelves for the copy to be given its own', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );
        await screen.findByDisplayValue('Wi-Fi Standard');

        expect(
            screen.queryByRole('button', { name: 'unpick-Router' }),
        ).not.toBeInTheDocument();
    });

    it('says where it came from and what it left out', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );

        expect(
            await screen.findByText(/Copied from Wi-Fi Standard/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/Shelves/)).toBeInTheDocument();
    });

    /* Nobody has answered a filter that does not exist yet. */
    it('carries no product counts into the copy', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );
        await screen.findByDisplayValue('Wi-Fi Standard');

        // Every answer is removable: none of them is tagged yet.
        expect(screen.queryByText(/tagged/i)).not.toBeInTheDocument();
    });

    /* Saving it is an ordinary create, so it goes through the same guards. */
    it('creates it when saved', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );
        await screen.findByDisplayValue('Wi-Fi Standard');

        await user.click(
            screen.getByRole('button', { name: /create filter/i }),
        );

        await waitFor(() => expect(post).toHaveBeenCalled());

        const [, payload] = post.mock.calls[0];

        expect(payload.name).toBe('Wi-Fi Standard');
        expect(payload.category_ids).toEqual([]);
        expect(payload.values.map((v) => v.label)).toEqual([
            'Wi-Fi 5',
            'Wi-Fi 6',
        ]);
        // New rows, not the originals moved across.
        expect(payload.values.every((v) => v.id === undefined)).toBe(true);
    });

    /* The same contract as the product copy: an edit is never a copy. */
    it('never claims an edit is a copy', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );
        expect(
            await screen.findByText(/Copied from Wi-Fi Standard/i),
        ).toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: /^edit wi-fi standard$/i }),
        );

        expect(screen.queryByText(/Copied from/i)).not.toBeInTheDocument();
    });

    it('never claims a fresh filter is a copy', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^copy wi-fi standard$/i }),
        );
        await screen.findByText(/Copied from Wi-Fi Standard/i);

        await user.click(screen.getByRole('button', { name: /add filter/i }));

        expect(screen.queryByText(/Copied from/i)).not.toBeInTheDocument();
        expect(screen.getByLabelText(/question/i)).toHaveValue('');
    });

    it('warns before deleting a filter that products answer', async () => {
        const user = userEvent.setup();
        render(<Attributes attributes={[enumFilter]} counts={{}} />);

        await user.click(
            screen.getByRole('button', { name: /^delete wi-fi standard$/i }),
        );

        expect(
            screen.getByText(/4 product\(s\) answer this filter/i),
        ).toBeInTheDocument();
    });
});
