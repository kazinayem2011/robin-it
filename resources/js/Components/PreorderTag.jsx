import React from 'react';
import { Clock } from 'lucide-react';
import { preorderDate } from '../utils/orderable';

/**
 * The mark on a line that ships later: in the cart, at checkout, on the
 * customer's orders and tracking page, and in the admin.
 *
 * An order mixing stock and pre-order lines is not one shipment, and only the
 * invoice said which line was waiting. Amber, as every pre-order is shown.
 *
 * @param {string|null} expected  the product's expected date, when there is one
 * @param {boolean}     compact   the tag alone, for a dense table row
 * @param {boolean}     waiting   owed because more was ordered than was in
 *                                stock, not a pre-order: "Waiting for stock"
 */
export default function PreorderTag({
    expected = null,
    compact = false,
    waiting = false,
}) {
    const date = waiting ? null : preorderDate(expected);

    return (
        <span className="preorder-tag">
            <Clock size={12} aria-hidden="true" />
            <span>
                {waiting ? 'Waiting for stock' : 'Pre-order'}
                {!compact && (
                    <>
                        {' '}
                        {waiting
                            ? '— ships when the next delivery arrives'
                            : '— ships when the delivery arrives'}
                        {date ? `, expected ${date}` : ''}
                    </>
                )}
            </span>
        </span>
    );
}
