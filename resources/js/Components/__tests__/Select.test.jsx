import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import Select from '../Select';

/**
 * The dropdown the shop draws itself.
 *
 * The reason it exists is that a native <select>'s open list is drawn by the
 * operating system and no stylesheet can reach it. The risk in replacing one
 * is that the replacement looks right and behaves worse — so most of what is
 * checked here is the behaviour a native select gave away for free: the
 * keyboard, type-ahead, and a value that still reaches the form.
 */

const FRUIT = [
    { value: 'apple', label: 'Apple' },
    { value: 'banana', label: 'Banana' },
    { value: 'cherry', label: 'Cherry' },
];

const open = async (user, name = /choose/i) => {
    await user.click(screen.getByRole('combobox', { name }));

    return screen.getByRole('listbox');
};

describe('Select', () => {
    it('shows the chosen option, not its value', () => {
        render(
            <Select
                aria-label="Choose fruit"
                value="banana"
                options={FRUIT}
                onChange={() => {}}
            />,
        );

        expect(screen.getByRole('combobox')).toHaveTextContent('Banana');
        expect(screen.getByRole('combobox')).not.toHaveTextContent('banana');
    });

    it('falls back to the placeholder with nothing chosen', () => {
        render(
            <Select
                aria-label="Choose fruit"
                value=""
                placeholder="Pick one…"
                options={FRUIT}
            />,
        );

        expect(screen.getByRole('combobox')).toHaveTextContent('Pick one…');
    });

    /* Closed until asked. A listbox rendered up front is a menu, not a select. */
    it('opens only on request, and closes again', async () => {
        const user = userEvent.setup();

        render(
            <Select aria-label="Choose fruit" value="apple" options={FRUIT} />,
        );

        expect(screen.queryByRole('listbox')).toBeNull();

        const list = await open(user);
        expect(within(list).getAllByRole('option')).toHaveLength(3);

        await user.keyboard('{Escape}');
        expect(screen.queryByRole('listbox')).toBeNull();
    });

    /*
     * The shape of the event matters as much as the value: every caller was
     * written against a <select> and reads `e.target.value`, and the Formik
     * ones read `e.target.name` to know which field moved.
     */
    it('reports a choice the way the select it replaced did', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(
            <Select
                aria-label="Choose fruit"
                name="fruit"
                value="apple"
                options={FRUIT}
                onChange={onChange}
            />,
        );

        await open(user);
        await user.click(screen.getByRole('option', { name: /cherry/i }));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange.mock.calls[0][0].target).toMatchObject({
            name: 'fruit',
            value: 'cherry',
        });

        // And it puts itself away, rather than leaving the list over the page.
        expect(screen.queryByRole('listbox')).toBeNull();
    });

    it('marks which option is the current one', async () => {
        const user = userEvent.setup();

        render(
            <Select aria-label="Choose fruit" value="banana" options={FRUIT} />,
        );

        await open(user);

        expect(screen.getByRole('option', { name: /banana/i })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('option', { name: /apple/i })).toHaveAttribute(
            'aria-selected',
            'false',
        );
    });

    describe('the keyboard', () => {
        it('arrows from the current choice and picks with Enter', async () => {
            const user = userEvent.setup();
            const onChange = vi.fn();

            render(
                <Select
                    aria-label="Choose fruit"
                    value="apple"
                    options={FRUIT}
                    onChange={onChange}
                />,
            );

            await user.tab();
            await user.keyboard('{ArrowDown}'); // opens, sitting on Apple
            await user.keyboard('{ArrowDown}'); // Banana
            await user.keyboard('{Enter}');

            expect(onChange.mock.calls[0][0].target.value).toBe('banana');
        });

        /* A native select does this, and losing it would be a downgrade
           nobody asked for. */
        it('jumps to an option when you type its first letter', async () => {
            const user = userEvent.setup();
            const onChange = vi.fn();

            render(
                <Select
                    aria-label="Choose fruit"
                    value="apple"
                    options={FRUIT}
                    onChange={onChange}
                />,
            );

            await user.tab();
            await user.keyboard('c');

            expect(onChange.mock.calls[0][0].target.value).toBe('cherry');
        });

        /*
         * Half of these live inside a modal, and Modal listens for Escape on
         * the window — as the outer listener, so a bubbling Escape reached it
         * too. Opening the division picker in the address book and pressing
         * Escape closed the address form out from under it.
         */
        it('keeps Escape to itself, so a surrounding modal stays open', async () => {
            const user = userEvent.setup();
            const outer = vi.fn();

            window.addEventListener('keydown', outer);

            try {
                render(
                    <Select
                        aria-label="Choose fruit"
                        value="apple"
                        options={FRUIT}
                    />,
                );

                await open(user);
                await user.keyboard('{Escape}');

                expect(screen.queryByRole('listbox')).toBeNull();
                expect(outer).not.toHaveBeenCalled();
            } finally {
                window.removeEventListener('keydown', outer);
            }
        });

        it('closes without choosing on Escape', async () => {
            const user = userEvent.setup();
            const onChange = vi.fn();

            render(
                <Select
                    aria-label="Choose fruit"
                    value="apple"
                    options={FRUIT}
                    onChange={onChange}
                />,
            );

            await open(user);
            await user.keyboard('{ArrowDown}{Escape}');

            expect(onChange).not.toHaveBeenCalled();
            expect(screen.queryByRole('listbox')).toBeNull();
        });
    });

    describe('a long list', () => {
        const MANY = Array.from({ length: 30 }, (_, i) => ({
            value: `p${i}`,
            label: `Product ${i}`,
        }));

        it('grows a search box, and a short one does not', async () => {
            const user = userEvent.setup();

            const { unmount } = render(
                <Select aria-label="Choose fruit" options={FRUIT} />,
            );
            await open(user);
            expect(screen.queryByRole('textbox')).toBeNull();
            unmount();

            render(<Select aria-label="Choose product" options={MANY} />);
            await open(user, /choose product/i);
            expect(screen.getByRole('textbox')).toBeTruthy();
        });

        it('narrows to what was typed', async () => {
            const user = userEvent.setup();

            render(<Select aria-label="Choose product" options={MANY} />);
            await open(user, /choose product/i);
            await user.type(screen.getByRole('textbox'), 'Product 17');

            expect(screen.getAllByRole('option')).toHaveLength(1);
            expect(screen.getByRole('option')).toHaveTextContent('Product 17');
        });

        it('says so when nothing matches, rather than showing an empty box', async () => {
            const user = userEvent.setup();

            render(<Select aria-label="Choose product" options={MANY} />);
            await open(user, /choose product/i);
            await user.type(screen.getByRole('textbox'), 'zzz');

            expect(screen.queryAllByRole('option')).toHaveLength(0);
            expect(screen.getByText(/nothing matches/i)).toBeTruthy();
        });
    });

    describe('as a form field', () => {
        const formik = (values, touched = {}, errors = {}) => ({
            values,
            touched,
            errors,
            handleChange: vi.fn(),
            handleBlur: vi.fn(),
        });

        it('drives Formik through the same handler a select used', async () => {
            const user = userEvent.setup();
            const f = formik({ fruit: 'apple' });

            render(
                <Select
                    label="Fruit"
                    name="fruit"
                    formik={f}
                    options={FRUIT}
                />,
            );

            await user.click(screen.getByRole('combobox', { name: /fruit/i }));
            await user.click(screen.getByRole('option', { name: /banana/i }));

            expect(f.handleChange).toHaveBeenCalledTimes(1);
            expect(f.handleChange.mock.calls[0][0].target).toMatchObject({
                name: 'fruit',
                value: 'banana',
            });
        });

        it('shows a touched field’s error', () => {
            render(
                <Select
                    label="Fruit"
                    name="fruit"
                    formik={formik(
                        { fruit: '' },
                        { fruit: true },
                        { fruit: 'Pick a fruit.' },
                    )}
                    options={FRUIT}
                />,
            );

            expect(screen.getByText('Pick a fruit.')).toBeTruthy();
            expect(screen.getByRole('combobox')).toHaveAttribute(
                'aria-invalid',
                'true',
            );
        });

        it('ties its label to the control', () => {
            render(<Select label="Fruit" name="fruit" options={FRUIT} />);

            // getByRole with a name only finds it if the label is associated.
            expect(
                screen.getByRole('combobox', { name: /fruit/i }),
            ).toBeTruthy();
        });

        /* A button posts nothing. Anything not going through Formik would
           have quietly lost the field. */
        it('still posts its value in a plain form', () => {
            const { container } = render(
                <Select name="fruit" value="cherry" options={FRUIT} />,
            );

            expect(
                container.querySelector('input[type="hidden"][name="fruit"]'),
            ).toHaveValue('cherry');
        });
    });

    it('cannot be opened when disabled', async () => {
        const user = userEvent.setup();

        render(
            <Select
                aria-label="Choose fruit"
                value="apple"
                options={FRUIT}
                disabled
            />,
        );

        await user.click(screen.getByRole('combobox'));

        expect(screen.queryByRole('listbox')).toBeNull();
    });

    it('accepts a plain list of strings', async () => {
        const user = userEvent.setup();

        render(
            <Select aria-label="Choose fruit" options={['Dhaka', 'Sylhet']} />,
        );
        await open(user);

        expect(screen.getByRole('option', { name: 'Dhaka' })).toBeTruthy();
    });
});
