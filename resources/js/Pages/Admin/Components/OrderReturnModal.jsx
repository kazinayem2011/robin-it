import React, { useEffect, useMemo, useState } from 'react';
import { Button, FormInput, Modal, toast } from '../../../Components';
import { adminService } from '../../../services';

/**
 * Taking back a delivered order, item by item.
 *
 * Condition is asked for per line because it decides where the units go:
 * resellable stock returns to the shelf, damaged stock is written off. Putting
 * a broken part back on sale is the failure this is here to prevent.
 */
export default function OrderReturnModal({ order, onClose, onSaved }) {
    const [lines, setLines] = useState({});
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);

    const items = useMemo(() => order?.items || [], [order]);

    useEffect(() => {
        if (!order) return;

        setNote('');
        setLines(
            Object.fromEntries(
                (order.items || []).map((item) => [
                    item.id,
                    { resellable: '', damaged: '' },
                ]),
            ),
        );
    }, [order]);

    const outstanding = (item) =>
        Math.max(0, (item.quantity || 0) - (item.returned_quantity || 0));

    const patch = (id, key, value) =>
        setLines((prev) => ({
            ...prev,
            [id]: { ...prev[id], [key]: value },
        }));

    const totals = useMemo(() => {
        let back = 0;
        let lost = 0;

        items.forEach((item) => {
            back += Number(lines[item.id]?.resellable) || 0;
            lost += Number(lines[item.id]?.damaged) || 0;
        });

        return { back, lost };
    }, [items, lines]);

    const overLine = items.find((item) => {
        const line = lines[item.id] || {};
        const total =
            (Number(line.resellable) || 0) + (Number(line.damaged) || 0);

        return total > outstanding(item);
    });

    const submit = async () => {
        if (totals.back + totals.lost === 0) {
            toast.error('Enter how many units came back.');
            return;
        }

        if (overLine) {
            toast.error(
                `Only ${outstanding(overLine)} of "${overLine.product_name}" are still outstanding.`,
            );
            return;
        }

        setSaving(true);

        try {
            const payload = items
                .map((item) => ({
                    order_item_id: item.id,
                    resellable: Number(lines[item.id]?.resellable) || 0,
                    damaged: Number(lines[item.id]?.damaged) || 0,
                }))
                .filter((l) => l.resellable > 0 || l.damaged > 0);

            await adminService.returnOrder(order.id, {
                lines: payload,
                note: note || null,
            });
            toast.success(
                `Return recorded — ${totals.back} back to stock` +
                    (totals.lost > 0 ? `, ${totals.lost} written off.` : '.'),
            );
            onSaved?.();
        } catch (err) {
            toast.error(err?.message || 'Could not record this return.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(order)}
            onClose={onClose}
            title={`Return — ${order?.order_number || ''}`}
            maxWidth="720px"
            footer={
                <div className="admin-receive-footer">
                    <div className="admin-receive-total">
                        <strong>{totals.back}</strong> back to stock
                        {totals.lost > 0 && (
                            <span> · {totals.lost} written off</span>
                        )}
                    </div>
                    <div className="admin-input-row-flex">
                        <Button variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            onClick={submit}
                            disabled={saving || Boolean(overLine)}
                        >
                            {saving ? 'Recording…' : 'Confirm return'}
                        </Button>
                    </div>
                </div>
            }
        >
            <p className="admin-field-hint admin-stock-intro">
                Resellable units go back on the shelf. Damaged units are
                recorded as a loss and are never sold again.
            </p>

            <div className="admin-table-responsive">
                <table className="admin-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Outstanding</th>
                            <th>Resellable</th>
                            <th>Damaged</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => {
                            const left = outstanding(item);
                            const line = lines[item.id] || {};
                            const over =
                                (Number(line.resellable) || 0) +
                                    (Number(line.damaged) || 0) >
                                left;

                            return (
                                <tr key={item.id}>
                                    <td>
                                        <div>{item.product_name}</div>
                                        {item.variant_name && (
                                            <div className="admin-field-hint">
                                                {item.variant_name}
                                            </div>
                                        )}
                                    </td>
                                    <td
                                        className={
                                            over ? 'admin-ledger-out' : ''
                                        }
                                    >
                                        {left}
                                    </td>
                                    <td>
                                        <FormInput
                                            type="number"
                                            min="0"
                                            max={left}
                                            value={line.resellable ?? ''}
                                            onChange={(e) =>
                                                patch(
                                                    item.id,
                                                    'resellable',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={left === 0}
                                        />
                                    </td>
                                    <td>
                                        <FormInput
                                            type="number"
                                            min="0"
                                            max={left}
                                            value={line.damaged ?? ''}
                                            onChange={(e) =>
                                                patch(
                                                    item.id,
                                                    'damaged',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={left === 0}
                                        />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {overLine && (
                <div className="admin-ledger-drift">
                    &ldquo;{overLine.product_name}&rdquo; has only{' '}
                    {outstanding(overLine)} outstanding — you cannot take back
                    more than was bought.
                </div>
            )}

            <FormInput
                label="Note"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Why was it returned?"
            />
        </Modal>
    );
}
