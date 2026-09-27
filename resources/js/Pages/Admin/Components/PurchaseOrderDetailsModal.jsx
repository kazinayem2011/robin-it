import React, { useEffect, useState } from 'react';
import Modal from '@/Components/Modal';
import Button from '@/Components/Button';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';

/**
 * Everything about one purchase order.
 *
 * The list shows totals only, so "which branch got how many of that delivery"
 * had no answer anywhere on screen. This shows what was asked for, line by
 * line, and every delivery against it: when, the invoice, who took it in, how
 * many went to each branch, and the serial numbers.
 */
export default function PurchaseOrderDetailsModal({
    orderId,
    onClose,
    onEdit,
    onReceive,
}) {
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!orderId) return undefined;

        let live = true;
        setData(null);
        setFailed(false);

        adminService
            .getPurchaseOrder(orderId)
            .then((res) => live && setData(res))
            .catch(() => live && setFailed(true));

        return () => {
            live = false;
        };
    }, [orderId]);

    const order = data?.order;
    const deliveries = data?.deliveries ?? [];
    const open = order && ['draft', 'sent', 'partial'].includes(order.status);

    return (
        <Modal
            isOpen={Boolean(orderId)}
            onClose={onClose}
            title={
                order ? `Purchase order ${order.reference}` : 'Purchase order'
            }
            maxWidth="760px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Close
                    </Button>
                    {open && (
                        <>
                            <Button
                                variant="secondary"
                                onClick={() => onEdit(order)}
                            >
                                Edit order
                            </Button>
                            <Button onClick={() => onReceive(order)}>
                                Receive delivery
                            </Button>
                        </>
                    )}
                </>
            }
        >
            {failed && (
                <p className="admin-field-hint">
                    Could not load this order. Close and try again.
                </p>
            )}
            {!order && !failed && <p className="admin-field-hint">Loading…</p>}

            {order && (
                <div>
                    <dl className="po-details-facts">
                        <div>
                            <dt>Supplier</dt>
                            <dd>{order.supplier_name}</dd>
                        </div>
                        <div>
                            <dt>Where it is</dt>
                            <dd>
                                <span className={`po-badge po-${order.status}`}>
                                    {order.status_label}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt>Ordered by</dt>
                            <dd>
                                {order.ordered_by_name || '—'}
                                {order.created_at &&
                                    `, ${String(order.created_at).slice(0, 10)}`}
                            </dd>
                        </div>
                        <div>
                            <dt>Expected on</dt>
                            <dd>
                                {order.expected_on
                                    ? String(order.expected_on).slice(0, 10)
                                    : '—'}
                            </dd>
                        </div>
                        {order.store?.name && (
                            <div>
                                <dt>Coming into</dt>
                                <dd>{order.store.name}</dd>
                            </div>
                        )}
                        {order.note && (
                            <div className="po-details-wide">
                                <dt>Note</dt>
                                <dd>{order.note}</dd>
                            </div>
                        )}
                    </dl>

                    <h4 className="po-details-heading">What was ordered</h4>
                    <div className="po-details-scroll">
                        <table className="po-lines po-details-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th className="po-num">Ordered</th>
                                    <th className="po-num">Arrived</th>
                                    <th className="po-num">Still owed</th>
                                    <th className="po-num">Cost each</th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.items.map((i) => (
                                    <tr key={i.id}>
                                        <td>{i.display_name}</td>
                                        <td className="po-num">{i.quantity}</td>
                                        <td className="po-num">
                                            {i.quantity_received}
                                        </td>
                                        <td className="po-num">
                                            {order.status === 'cancelled' ? (
                                                '—'
                                            ) : i.outstanding > 0 ? (
                                                <strong className="po-outstanding">
                                                    {i.outstanding}
                                                </strong>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="po-num">
                                            {i.unit_cost === null
                                                ? '—'
                                                : formatBdt(i.unit_cost)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Total</td>
                                    <td className="po-num">
                                        {order.total_quantity}
                                    </td>
                                    <td className="po-num">
                                        {order.items.reduce(
                                            (n, i) => n + i.quantity_received,
                                            0,
                                        )}
                                    </td>
                                    <td className="po-num">
                                        {order.outstanding || '—'}
                                    </td>
                                    <td className="po-num">
                                        <strong>
                                            {formatBdt(order.total_cost)}
                                        </strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <h4 className="po-details-heading">
                        Deliveries ({deliveries.length})
                    </h4>
                    {deliveries.length === 0 && (
                        <p className="admin-field-hint">
                            Nothing has arrived against this order yet.
                        </p>
                    )}
                    {deliveries.map((d) => (
                        <section key={d.id} className="po-delivery">
                            <header className="po-delivery-head">
                                <strong>{d.received_on}</strong>
                                <span>{d.total_quantity} units</span>
                                {d.invoice_number && (
                                    <span>Invoice {d.invoice_number}</span>
                                )}
                                {d.received_by && (
                                    <span>Received by {d.received_by}</span>
                                )}
                                <span className="admin-field-hint">
                                    {d.reference}
                                </span>
                            </header>
                            {d.note && (
                                <p className="po-delivery-note">{d.note}</p>
                            )}
                            <ul className="po-delivery-lines">
                                {d.lines.map((l) => (
                                    <li key={l.name}>
                                        <span className="po-delivery-product">
                                            {l.quantity} × {l.name}
                                        </span>
                                        <span className="po-delivery-branches">
                                            {l.branches
                                                .map(
                                                    (b) =>
                                                        `${b.name}: ${b.quantity}`,
                                                )
                                                .join(' · ')}
                                        </span>
                                        {l.serials.length > 0 && (
                                            <span className="po-delivery-serials">
                                                Serials: {l.serials.join(', ')}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
        </Modal>
    );
}
