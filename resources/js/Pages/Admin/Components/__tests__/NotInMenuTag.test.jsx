import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import NotInMenuTag, { EmptyCategoriesContext } from '../NotInMenuTag';

const withEmpty = (ids, ui) => (
    <EmptyCategoriesContext.Provider value={new Set(ids)}>
        {ui}
    </EmptyCategoriesContext.Provider>
);

/* An empty category is left out of the menu; the tree says so, and why. */
describe('NotInMenuTag', () => {
    it('says a category with nothing in it is not in the menu', () => {
        render(withEmpty([7], <NotInMenuTag id={7} />));

        expect(
            screen.getByText('Not in menu — no products yet'),
        ).toBeInTheDocument();
        expect(
            screen.getByTitle(/appears as soon as one is added/),
        ).toBeTruthy();
    });

    it('is only the icon on a small chip, with the reason on hover', () => {
        render(withEmpty([7], <NotInMenuTag id={7} compact />));

        expect(screen.queryByText(/no products yet/)).not.toBeInTheDocument();
        expect(screen.getByLabelText(/Not shown in the menu yet/)).toBeTruthy();
    });

    it('says nothing about a category that is in the menu', () => {
        const { container } = render(withEmpty([7], <NotInMenuTag id={8} />));

        expect(container.textContent).toBe('');
    });
});
