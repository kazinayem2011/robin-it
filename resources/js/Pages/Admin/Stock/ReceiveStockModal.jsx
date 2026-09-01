import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useFormik } from 'formik';
import Button from '../../../Components/Button';
import FormInput from '../../../Components/FormInput';
import FormSelect from '../../../Components/FormSelect';
import Modal from '../../../Components/Modal';
import SearchableSelect from '../../../Components/SearchableSelect';
import { listFrom } from '../../../utils/apiPayload';
import { toast } from '../../../Components/Toast';
import { adminService } from '../../../services';
import { adminStockReceiptSchema } from '../../../validations';
import { formatBdt } from '../../../utils/formatters';
import { Plus, Trash2, Hash } from 'lucide-react';

const blankLine = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    unit: '',
    quantity: '',
    unit_cost: '',
    serials: '',
});

const emptyReceipt = () => ({
    supplier_id: '',
    invoice_number: '',
    received_on: new Date().toISOString().slice(0, 10),
    note: '',
    lines: [blankLine()],
});

/**
 * Booking a delivery from a supplier — the only way units enter the shelf.
 *
 * One receipt covers one invoice and can carry many lines, so a delivery stays
 * a single record rather than a scatter of unexplained quantity changes.
 */
/** How many serials somebody has actually typed, however they pasted them. */
const countSerials = (text) =>
    String(text || '')
        .split(/[\r\n,]+/)
        .map((x) => x.trim())
        .filter(Boolean).length;

export default function ReceiveStockModal({
    isOpen,
    onClose,
    onSaved,
    suppliers = [],
}) {
    // Only one line's serial box is open at a time; four textareas at once
    // is a wall, and a delivery is usually only serial-tracked on one line.
    const [openSerials, setOpenSerials] = useState(null);

    const [units, setUnits] = useState([]);

    const formik = useFormik({
        initialValues: emptyReceipt(),
        validationSchema: adminStockReceiptSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            const lines = values.lines
                .filter((l) => l.unit && Number(l.quantity) > 0)
                .map((l) => {
                    const [productId, variantId] = l.unit.split(':');

                    return {
                        product_id: Number(productId),
                        product_variant_id: variantId
                            ? Number(variantId)
                            : null,
                        quantity: Number(l.quantity),
                        unit_cost:
                            l.unit_cost === '' ? null : Number(l.unit_cost),
                        serials: l.serials?.trim() ? l.serials.trim() : null,
                    };
                });

            try {
                const receipt = await adminService.receiveStock({
                    supplier_id: values.supplier_id || null,
                    invoice_number: values.invoice_number || null,
                    received_on: values.received_on,
                    note: values.note || null,
                    lines,
                });
                toast.success(
                    `Received ${receipt?.total_quantity ?? 0} unit(s)${
                        receipt?.reference ? ` as ${receipt.reference}` : ''
                    }.`,
                );
                resetForm({ values: emptyReceipt() });
                onSaved?.();
            } catch (err) {
                toast.error(err?.message || 'Could not record this delivery.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    /*
     * The list of things that can be received.
     *
     * `res` is the API envelope, not the array — every other screen that reads
     * this endpoint unwraps `res.data`, and this one did not, so the picker
     * held nothing and no stock could be received through it at all.
     *
     * Searched server-side for the same reason the other screens do: the
     * endpoint returns fifty products of the shop's twelve hundred, so
     * filtering what has already arrived can never reach the rest.
     */
    const loadUnits = useCallback(async (term = '') => {
        try {
            const res = await adminService.getStockUnits(
                term ? { search: term } : {},
            );
            setUnits(listFrom(res));
        } catch {
            toast.error('Could not load the product list.');
            setUnits([]);
        }
    }, []);

    useEffect(() => {
        if (isOpen) loadUnits();
    }, [isOpen, loadUnits]);

    // A variant product can only be received into one of its options, since
    // that is where its stock lives.
    const options = useMemo(
        () =>
            units.flatMap((product) =>
                product.has_variants
                    ? (product.variants || [])
                          .filter((v) => v.is_active)
                          .map((v) => ({
                              value: `${product.id}:${v.id}`,
                              label: `${product.name} — ${v.name}`,
                              hint: `${v.stock_quantity} on hand`,
                          }))
                    : [
                          {
                              value: `${product.id}:`,
                              label: product.name,
                              hint: `${product.stock_quantity} on hand`,
                          },
                      ],
            ),
        [units],
    );

    const lines = formik.values.lines;

    const setLine = (key, patch) =>
        formik.setFieldValue(
            'lines',
            lines.map((l) => (l.key === key ? { ...l, ...patch } : l)),
        );

    const totals = useMemo(() => {
        let qty = 0;
        let cost = 0;

        lines.forEach((l) => {
            const q = Number(l.quantity) || 0;
            qty += q;
            cost += q * (Number(l.unit_cost) || 0);
        });

        return { qty, cost };
    }, [lines]);

    /* The chosen source is not a supplier but the opening-balance entry. */
    const openingSelected = suppliers.some(
        (s) =>
            String(s.id) === String(formik.values.supplier_id) &&
            s.kind === 'opening',
    );

    const lineError =
        typeof formik.errors.lines === 'string' && formik.submitCount > 0
            ? formik.errors.lines
            : null;

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title="Receive stock"
            maxWidth="820px"
            footer={
                <div className="admin-receive-footer">
                    <div className="admin-receive-total">
                        <strong>{totals.qty}</strong> unit(s)
                        {totals.cost > 0 && (
                            <span> · {formatBdt(totals.cost)}</span>
                        )}
                    </div>
                    <div className="admin-input-row-flex">
                        <Button variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            onClick={formik.handleSubmit}
                            disabled={formik.isSubmitting}
                        >
                            {formik.isSubmitting
                                ? 'Recording…'
                                : 'Receive into stock'}
                        </Button>
                    </div>
                </div>
            }
        >
            <form onSubmit={formik.handleSubmit} noValidate>
                <div className="admin-grid-3col">
                    {/*
                     * Suppliers are maintained in their own section; this only
                     * picks one. The last entry is not a supplier at all — it
                     * is the source for stock the shop already held when it
                     * started keeping books, which used to be typed onto the
                     * product form instead and left no paperwork behind it.
                     */}
                    <FormSelect
                        label="Source"
                        name="supplier_id"
                        formik={formik}
                        placeholder={
                            suppliers.length
                                ? 'Choose a supplier…'
                                : 'No suppliers yet'
                        }
                        options={suppliers.map((s) => ({
                            value: String(s.id),
                            label:
                                s.kind === 'opening'
                                    ? `${s.name} — stock you already held`
                                    : s.name,
                        }))}
                        helperText={
                            openingSelected
                                ? 'Recorded as an opening balance, not a purchase.'
                                : ''
                        }
                    />

                    <FormInput
                        label="Invoice number"
                        name="invoice_number"
                        formik={formik}
                        placeholder="INV-99321"
                    />

                    <FormInput
                        label="Received on"
                        name="received_on"
                        required
                        type="date"
                        formik={formik}
                    />
                </div>

                <div className="admin-receive-lines">
                    {lines.map((line) => (
                        <div className="admin-receive-line" key={line.key}>
                            <div className="admin-receive-line-product">
                                <SearchableSelect
                                    onSearch={loadUnits}
                                    label="Product"
                                    value={line.unit}
                                    onChange={(e) =>
                                        setLine(line.key, {
                                            unit: e.target.value,
                                        })
                                    }
                                    placeholder="Choose a product…"
                                    searchPlaceholder="Type a product name…"
                                    options={options}
                                />
                            </div>

                            <FormInput
                                label="Qty"
                                type="number"
                                min="1"
                                value={line.quantity}
                                onChange={(e) =>
                                    setLine(line.key, {
                                        quantity: e.target.value,
                                    })
                                }
                            />

                            <FormInput
                                label="Unit cost"
                                type="number"
                                min="0"
                                step="0.01"
                                value={line.unit_cost}
                                onChange={(e) =>
                                    setLine(line.key, {
                                        unit_cost: e.target.value,
                                    })
                                }
                                placeholder="Optional"
                            />

                            <button
                                type="button"
                                className={`admin-receive-line-serials ${line.serials?.trim() ? 'has-serials' : ''}`}
                                title="Serial numbers for this line"
                                onClick={() =>
                                    setOpenSerials(
                                        openSerials === line.key
                                            ? null
                                            : line.key,
                                    )
                                }
                            >
                                <Hash size={14} />
                                {line.serials?.trim()
                                    ? countSerials(line.serials)
                                    : ''}
                            </button>

                            <button
                                type="button"
                                className="admin-receive-line-remove"
                                title="Remove this line"
                                disabled={lines.length === 1}
                                onClick={() =>
                                    formik.setFieldValue(
                                        'lines',
                                        lines.filter((l) => l.key !== line.key),
                                    )
                                }
                            >
                                <Trash2 size={15} />
                            </button>

                            {/*
                             * Optional, and only in the way when asked for.
                             * Most of a shop's stock — cables, paste, a bag of
                             * screws — has no serial worth keeping, and a box
                             * on every line would make receiving a chore.
                             */}
                            {openSerials === line.key && (
                                <div className="admin-receive-serials">
                                    <label>
                                        Serial numbers — one per line
                                        <textarea
                                            rows={4}
                                            value={line.serials}
                                            onChange={(e) =>
                                                setLine(line.key, {
                                                    serials: e.target.value,
                                                })
                                            }
                                            placeholder={
                                                'SN-4090-0001\nSN-4090-0002'
                                            }
                                        />
                                    </label>
                                    <span className="admin-field-hint">
                                        {countSerials(line.serials)} entered
                                        {Number(line.quantity) > 0 &&
                                            ` of ${line.quantity} received`}
                                        . Leave blank for anything you do not
                                        track by unit.
                                    </span>
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {lineError && (
                    <div className="admin-ledger-drift">{lineError}</div>
                )}

                <div className="admin-receive-add-line">
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={Plus}
                        onClick={() =>
                            formik.setFieldValue('lines', [
                                ...lines,
                                blankLine(),
                            ])
                        }
                    >
                        Add another line
                    </Button>
                </div>

                <FormInput
                    label="Note"
                    name="note"
                    formik={formik}
                    placeholder="Anything worth remembering about this delivery"
                />
            </form>
        </Modal>
    );
}
