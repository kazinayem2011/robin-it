import React from 'react';

/**
 * What the shop promises after the sale.
 *
 * Both fields were being recorded and neither was ever shown: the admin form
 * captured a period and a set of terms, and no storefront page, email or
 * invoice read either. Somebody could write four clauses of warranty policy
 * and no customer would ever see one.
 *
 * The two say different things and are shown as such. The period is the figure
 * the claims system counts from; the terms are the shop's own wording, typed a
 * clause per line, which is why they are read back as a list rather than run
 * together into a paragraph nobody finishes.
 */
export default function ProductWarranty({ months = null, terms = '' }) {
    const period = Number(months) || 0;
    /*
     * One clause per line is how the admin field asks for them. Blank lines
     * are dropped rather than becoming empty bullets — a double return between
     * clauses is the normal way to type a list.
     */
    const clauses = (terms || '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

    return (
        <div className="pdp-warranty">
            {period > 0 && (
                <p className="pdp-warranty-period">
                    <strong>
                        {period} month{period === 1 ? '' : 's'}
                    </strong>{' '}
                    from the date of purchase
                </p>
            )}

            {clauses.length > 1 ? (
                /* Typed a clause per line, so read as a list. A run of
                   sentences in one paragraph is what makes a warranty policy
                   go unread. */
                <ul className="pdp-warranty-terms">
                    {clauses.map((clause, i) => (
                        <li key={i}>{clause}</li>
                    ))}
                </ul>
            ) : clauses.length === 1 ? (
                <p className="pdp-warranty-single">{clauses[0]}</p>
            ) : (
                period === 0 && (
                    <p>
                        {/* Says what is true rather than implying a policy
                            exists. A shopper who reads "standard warranty
                            applies" goes looking for terms nobody wrote. */}
                        No warranty has been recorded for this product. Ask us
                        and we will confirm what it carries.
                    </p>
                )
            )}
        </div>
    );
}
