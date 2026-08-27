import React, { useEffect, useMemo } from 'react';
import { useFormik } from 'formik';
import Button from '../../../Components/Button';
import FormInput from '../../../Components/FormInput';
import Modal from '../../../Components/Modal';
import { toast } from '../../../Components/Toast';
import { adminService } from '../../../services';
import { adminOrderReturnSchema } from '../../../validations';

/**
 * Taking back a delivered order, item by item.
 *
 * Condition is asked for per line because it decides where the units go:
 * resellable stock returns to the shelf, damaged stock is written off. Putting
 * a broken part back on sale is the failure this is here to prevent.
 */
export default function OrderReturnModal({ order, onClose, onSaved }) {
    const items = useMemo(() => order?.items || [], [order]);

    const formik = useFormik({
        initialValues: { note: '', lines: {} },
        validationSchema: adminOrderReturnSchema,
        onSubmit: async (values, { setSubmitting }) => {
            const payload = items
                .map((item) => ({
                    order_item_id: item.id,
                    resellable: Number(values.lines[item.id]?.resellable) || 0,
                    damaged: Number(values.lines[item.id]?.damaged) || 0,
                }))
                .filter((l) => l.resellable > 0 || l.damaged > 0);

            try {
                await adminService.returnOrder(order.id, {
                    lines: payload,
                    note: values.note || null,
                });
                toast.success(
                    `Return recorded — ${totals.back} back to stock` +
                        (totals.lost > 0
                            ? `, ${totals.lost} written off.`
                            : '.'),
                );
                onSaved?.();
            } catch (err) {
                toast.error(err?.message || 'Could not record this return.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const lines = formik.values.lines;

    useEffect(() => {
        if (!order) return;

        formik.resetForm({
            values: {
                note: '',
                lines: Object.fromEntries(
                    (order.items || []).map((item) => [
                        item.id,
                        { resellable: '', damaged: '' },
                    ]),
                ),
            },
        });
        // Resetting on the order alone; depending on formik's identity loops.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order]);

    const outstanding = (item) =>
        Math.max(0, (item.quantity || 0) - (item.returned_quantity || 0));

    const patch = (id, key, value) =>
        formik.setFieldValue('lines', {
            ...lines,
            [id]: { ...lines[id], [key]: value },
        });

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
                            onClick={formik.handleSubmit}
                            disabled={formik.isSubmitting || Boolean(overLine)}
                        >
                            {formik.isSubmitting
                                ? 'Recording…'
                                : 'Confirm return'}
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
                name="note"
                formik={formik}
                placeholder="Why was it returned?"
            />
        </Modal>
    );
}
