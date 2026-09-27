import React, { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, beforeAll } from 'vitest';
import SearchableSelect from '../SearchableSelect';

const OPTIONS = [
    { value: 1, label: 'Khulna Branch' },
    { value: 2, label: 'Uttara Showroom' },
    { value: 3, label: 'Chattogram Branch' },
];

function Picker({ initial = '' }) {
    const [value, setValue] = useState(initial);
    return (
        <SearchableSelect
            label="Branch"
            name="branch"
            value={value}
            options={OPTIONS}
            onChange={(e) => setValue(e.target.value)}
        />
    );
}

beforeAll(() => {
    // jsdom has no layout, so nothing to scroll.
    Element.prototype.scrollIntoView = () => {};
});

describe('SearchableSelect — screen readers and keyboards', () => {
    it('says the trigger opens a list, and whether it is open', () => {
        render(<Picker />);
        const trigger = screen.getByRole('button', { name: /Branch/ });
        expect(trigger).toHaveAttribute('aria-haspopup', 'listbox');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(trigger).toHaveAttribute(
            'aria-controls',
            screen.getByRole('listbox').id,
        );
    });

    it('lists options, names the search box, and marks the chosen one', () => {
        render(<Picker initial={2} />);
        fireEvent.click(screen.getByRole('button', { name: /Branch/ }));

        const search = screen.getByRole('combobox', { name: 'Search branch' });
        expect(search).toHaveAttribute(
            'aria-controls',
            screen.getByRole('listbox', { name: 'Branch' }).id,
        );

        const options = screen.getAllByRole('option');
        expect(options).toHaveLength(3);
        expect(
            screen.getByRole('option', { name: 'Uttara Showroom' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.getByRole('option', { name: 'Khulna Branch' }),
        ).toHaveAttribute('aria-selected', 'false');
        expect(screen.getByRole('status')).toHaveTextContent('3 results');
    });

    it('follows the arrow keys with the highlighted option', () => {
        render(<Picker />);
        fireEvent.click(screen.getByRole('button', { name: /Branch/ }));
        const search = screen.getByRole('combobox');
        const first = search.getAttribute('aria-activedescendant');
        expect(document.getElementById(first)).toHaveTextContent('Khulna');

        fireEvent.keyDown(search, { key: 'ArrowDown' });
        const next = search.getAttribute('aria-activedescendant');
        expect(document.getElementById(next)).toHaveTextContent('Uttara');
    });

    it('chooses with Enter and hands focus back to the trigger', () => {
        render(<Picker />);
        const trigger = screen.getByRole('button', { name: /Branch/ });
        fireEvent.click(trigger);
        const search = screen.getByRole('combobox');
        fireEvent.keyDown(search, { key: 'ArrowDown' });
        fireEvent.keyDown(search, { key: 'Enter' });

        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(trigger).toHaveTextContent('Uttara Showroom');
        expect(trigger).toHaveFocus();
    });

    it('closes on Escape, back on the trigger, without choosing', () => {
        render(<Picker />);
        const trigger = screen.getByRole('button', { name: /Branch/ });
        fireEvent.click(trigger);
        fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Escape' });

        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
        expect(trigger).not.toHaveTextContent('Khulna');
    });

    it('opens from the keyboard with the down arrow', () => {
        render(<Picker />);
        const trigger = screen.getByRole('button', { name: /Branch/ });
        fireEvent.keyDown(trigger, { key: 'ArrowDown' });
        expect(screen.getByRole('listbox')).toBeInTheDocument();
    });

    it('chooses with a click', () => {
        render(<Picker />);
        fireEvent.click(screen.getByRole('button', { name: /Branch/ }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Chattogram Branch' }),
        );
        expect(
            screen.getByRole('button', { name: /Branch/ }),
        ).toHaveTextContent('Chattogram Branch');
    });
});
