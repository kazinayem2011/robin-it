import { ChevronDown } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import Modal from '../../../Components/Modal';
import { TableSkeleton } from '../../../Components/Skeleton';
import { adminService } from '../../../services';
import { formatBdt } from '../../../utils/formatters';

/**
 * Past deliveries.
 *
 * Every unit that entered the shelf came in on one of these, so this is where a
 * balance that looks wrong gets traced back to an invoice.
 */
export default function ReceiptHistoryModal({ isOpen, onClose }) {
    const [state, setState] = useState({ loading: false, receipts: [] });
    const [expanded, setExpanded] = useState(null);

    useEffect(() => {
        if (!isOpen) return;

        let cancelled = false;
        setState({ loading: true, receipts: [] });

        adminService
            .getStockReceipts()
            .then((res) => {
                if (cancelled) return;
                setState({ loading: false, receipts: res?.data || [] });
            })
            .catch(() => {
                if (!cancelled) setState({ loading: false, receipts: [] });
            });

        return () => {
            cancelled = true;
        };
    }, [isOpen]);

    const formatDate = (value) =>
        value
            ? new Date(value).toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
            : '—';

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title="Delivery history"
            maxWidth="820px"
        >
            {state.loading ? (
                <TableSkeleton
                    headers={[
                        'Reference',
                        'Supplier',
                        'Received',
                        'Units',
                        'Cost',
                    ]}
                    rows={4}
                />
            ) : state.receipts.length === 0 ? (
                <p className="admin-field-hint">
                    No deliveries recorded yet. Receiving stock will list them
                    here.
                </p>
            ) : (
                <div className="admin-table-responsive">
                    <table className="admin-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Supplier</th>
                                <th>Received</th>
                                <th>Units</th>
                                <th>Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state.receipts.map((receipt) => (
                                <React.Fragment key={receipt.id}>
                                    <tr
                                        className="admin-receipt-row"
                                        onClick={() =>
                                            setExpanded(
                                                expanded === receipt.id
                                                    ? null
                                                    : receipt.id,
                                            )
                                        }
                                    >
                                        <td>
                                            {/* A row that opens should look
                                                like one. */}
                                            <ChevronDown
                                                size={14}
                                                className={`admin-receipt-chevron ${expanded === receipt.id ? 'is-open' : ''}`}
                                            />
                                            <strong>{receipt.reference}</strong>
                                            {receipt.invoice_number && (
                                                <div className="admin-field-hint">
                                                    Invoice{' '}
                                                    {receipt.invoice_number}
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            {receipt.supplier_name || '—'}
                                            {receipt.user?.name && (
                                                <div className="admin-field-hint">
                                                    by {receipt.user.name}
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            {formatDate(receipt.received_on)}
                                        </td>
                                        <td>{receipt.total_quantity}</td>
                                        <td>
                                            {receipt.total_cost > 0
                                                ? formatBdt(receipt.total_cost)
                                                : '—'}
                                        </td>
                                    </tr>

                                    {expanded === receipt.id && (
                                        <tr>
                                            <td colSpan={5}>
                                                <ul className="admin-receipt-lines">
                                                    {(receipt.items || []).map(
                                                        (item) => (
                                                            <li key={item.id}>
                                                                <span>
                                                                    {item
                                                                        .product
                                                                        ?.name ||
                                                                        'Removed product'}
                                                                    {item
                                                                        .variant
                                                                        ?.name &&
                                                                        ` — ${item.variant.name}`}
                                                                </span>
                                                                <span>
                                                                    {
                                                                        item.quantity
                                                                    }{' '}
                                                                    ×{' '}
                                                                    {item.unit_cost
                                                                        ? formatBdt(
                                                                              item.unit_cost,
                                                                          )
                                                                        : '—'}
                                                                </span>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                                {receipt.note && (
                                                    <p className="admin-field-hint">
                                                        {receipt.note}
                                                    </p>
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </React.Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Modal>
    );
}
