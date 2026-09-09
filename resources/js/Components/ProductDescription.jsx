import React from 'react';

/**
 * The product description, read on the page rather than behind a tab.
 *
 * The markup is the shop's own, sanitised server-side through RichText before
 * it is stored, which is why it can be set as HTML here.
 */
export default function ProductDescription({ description }) {
    return (
        <div className="description-content">
            <div
                dangerouslySetInnerHTML={{
                    __html:
                        description ||
                        '<p>Genuine product supplied with official manufacturer warranty and full accessories.</p>',
                }}
            />
        </div>
    );
}
