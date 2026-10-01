import React from 'react';
import {
    render,
    screen,
    waitFor,
    fireEvent,
    act,
} from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

const get = vi.fn();

vi.mock('../../services/axiosInstance', () => ({
    default: { get: (...a) => get(...a) },
}));

const { default: CategoryPicker } = await import('../CategoryPicker');

/*
 * On a slow connection the empty search made on opening came back after the
 * one for "Phone", and the list showed every category with "Phone" typed.
 */
describe('CategoryPicker on a slow connection', () => {
    it('keeps the answer to what is typed, not a late one to an older search', async () => {
        let answerEmpty;
        get.mockImplementation((url, { params }) =>
            params.q === ''
                ? new Promise((resolve) => {
                      answerEmpty = () =>
                          resolve({
                              data: [
                                  {
                                      id: 1,
                                      name: '1STPLAYER',
                                      path: 'Component',
                                  },
                              ],
                          });
                  })
                : Promise.resolve({
                      data: [{ id: 2, name: 'Phone', path: '' }],
                  }),
        );
        render(<CategoryPicker label="Category" onChange={vi.fn()} />);

        const box = screen.getByRole('textbox');
        fireEvent.focus(box);
        await waitFor(() => expect(get).toHaveBeenCalledTimes(1));
        fireEvent.change(box, { target: { value: 'Phone' } });
        expect(await screen.findByText('Phone')).toBeInTheDocument();

        await act(async () => answerEmpty());

        expect(screen.queryByText('1STPLAYER')).not.toBeInTheDocument();
        expect(screen.getByText('Phone')).toBeInTheDocument();
    });
});
