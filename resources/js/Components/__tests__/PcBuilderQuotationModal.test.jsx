import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import { PcBuilderQuotationModal } from '../PcBuilderQuotationModal';

/**
 * Who the quotation is for.
 *
 * The sheet printed a customer name and a phone but offered no way to enter
 * either, so every "official quotation" went out addressed to "Valued
 * Customer". The two boxes in the toolbar fill that line in — and because the
 * toolbar is hidden when printing, they never appear on the sheet themselves.
 */
describe('PcBuilderQuotationModal', () => {
    const open = () =>
        render(
            <PcBuilderQuotationModal
                isOpen
                onClose={() => {}}
                /* The shape the builder passes: a slot with its chosen
                   product hanging off it. */
                components={[
                    {
                        id: 'component-processor',
                        name: 'Processor',
                        product: { id: 1, name: 'Sample AMD', price: 5750 },
                    },
                ]}
                totalPrice={5750}
            />,
        );

    const clientBar = () => document.querySelector('.quotation-client-bar');

    it('addresses the sheet to Valued Customer until somebody is named', () => {
        open();

        expect(clientBar()).toHaveTextContent('Valued Customer');
    });

    it('writes the typed name onto the sheet', async () => {
        const user = userEvent.setup();
        open();

        await user.type(
            screen.getByLabelText(/customer name for this quotation/i),
            'Kazi Nayem',
        );

        expect(clientBar()).toHaveTextContent('Kazi Nayem');
        expect(clientBar()).not.toHaveTextContent('Valued Customer');
    });

    /* The phone line is absent entirely rather than printed empty. */
    it('adds the phone line only once a number is given', async () => {
        const user = userEvent.setup();
        open();

        expect(clientBar()).not.toHaveTextContent(/contact phone/i);

        await user.type(
            screen.getByLabelText(/customer phone for this quotation/i),
            '01712345678',
        );

        expect(clientBar()).toHaveTextContent('01712345678');
    });

    /* A name of nothing but spaces is not a name. */
    it('falls back when the name is only whitespace', async () => {
        const user = userEvent.setup();
        open();

        await user.type(
            screen.getByLabelText(/customer name for this quotation/i),
            '   ',
        );

        expect(clientBar()).toHaveTextContent('Valued Customer');
    });

    /*
     * The bar lays out three entries once a phone is given, and it used to
     * space them with justify-content alone — which works at two and collides
     * at three. A gap is what holds them apart whatever the count.
     */
    it('keeps the entries apart with a gap, not with spare room', async () => {
        const css = await import('node:fs').then((fs) =>
            fs.readFileSync(
                'resources/js/Components/PcBuilderQuotationModal.css',
                'utf8',
            ),
        );

        const rule = css.slice(
            css.indexOf('.quotation-client-bar {'),
            css.indexOf('}', css.indexOf('.quotation-client-bar {')),
        );

        expect(rule).toMatch(/gap:/);
    });
});
