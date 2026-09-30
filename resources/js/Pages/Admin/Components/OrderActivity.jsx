import React, { useEffect, useState } from 'react';
import { adminService } from '@/services';
import './OrderActivity.css';

/**
 * What happened to an order, oldest first, and who did each thing.
 *
 * A laptop order went placed → paid → shipped → refunded → returned within
 * minutes, and this window could say none of it beyond the money. Loaded when
 * the order is opened rather than with the list, because nobody needs forty
 * orders' histories to read one.
 */
export default function OrderActivity({ orderId }) {
    const [rows, setRows] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!orderId) return undefined;

        let live = true;
        setRows(null);
        setFailed(false);

        adminService
            .getOrderActivity(orderId)
            .then((res) => live && setRows(res?.data ?? res ?? []))
            .catch(() => live && setFailed(true));

        return () => {
            live = false;
        };
    }, [orderId]);

    return (
        <div className="admin-detail-panel">
            <span className="admin-detail-panel-label">Activity</span>

            {failed && (
                <p className="admin-field-hint">
                    Could not load this order&apos;s history. Close and open it
                    again.
                </p>
            )}

            {!failed && rows === null && (
                <p className="admin-field-hint">Loading…</p>
            )}

            {rows && rows.length > 0 && (
                <ol className="order-activity-list">
                    {rows.map((row, i) => (
                        <li
                            key={`${row.at}-${i}`}
                            className={`order-activity-item is-${row.kind}`}
                        >
                            <div className="order-activity-head">
                                <span className="order-activity-title">
                                    {row.title}
                                </span>
                                <span className="order-activity-when">
                                    {row.when}
                                </span>
                            </div>
                            {(row.detail || row.by) && (
                                <div className="order-activity-meta">
                                    {row.detail}
                                    {row.detail && row.by ? ' · ' : ''}
                                    {row.by && (
                                        <span
                                            className={
                                                row.by === 'not recorded'
                                                    ? 'order-activity-unknown'
                                                    : undefined
                                            }
                                        >
                                            by {row.by}
                                        </span>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
