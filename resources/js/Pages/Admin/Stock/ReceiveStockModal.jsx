import React, { useEffect, useMemo, useState } from 'react';
import { useFormik } from 'formik';
import {
    Button,
    FormInput,
    FormSelect,
    Modal,
    SearchableSelect,
    toast,
} from '../../../Components';
import { adminService } from '../../../services';
import {
    adminStockReceiptSchema,
    adminSupplierSchema,
} from '../../../validations';
import { formatBdt } from '../../../utils/formatters';
import { Plus, Trash2 } from 'lucide-react';

const blankLine = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    unit: '',
    quantity: '',
    unit_cost: '',
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
export default function ReceiveStockModal({
    isOpen,
    onClose,
    onSaved,
    suppliers = [],
}) {
    const [units, setUnits] = useState([]);
    const [supplierList, setSupplierList] = useState(suppliers);
    // Adding a supplier mid-delivery is normal; bouncing to another screen
    // would lose the half-entered receipt.
    const [addingSupplier, setAddingSupplier] = useState(false);

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

    const supplierForm = useFormik({
        initialValues: { name: '', phone: '' },
        validationSchema: adminSupplierSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                const created = await adminService.createSupplier({
                    name: values.name.trim(),
                    phone: values.phone || null,
                });
                setSupplierList((prev) =>
                    [...prev, created].sort((a, b) =>
                        a.name.localeCompare(b.name),
                    ),
                );
                formik.setFieldValue('supplier_id', String(created.id));
                setAddingSupplier(false);
                resetForm();
                toast.success(`Supplier "${created.name}" added.`);
            } catch (err) {
                toast.error(err?.message || 'Could not add that supplier.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    useEffect(() => setSupplierList(suppliers), [suppliers]);

    useEffect(() => {
        if (!isOpen) return;

        let cancelled = false;

        adminService
            .getStockUnits()
            .then((res) => {
                if (!cancelled) setUnits(Array.isArray(res) ? res : []);
            })
            .catch(() => {
                if (!cancelled) toast.error('Could not load the product list.');
            });

        return () => {
            cancelled = true;
        };
    }, [isOpen]);

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
            <form onSubmit={formik.handleSubmit}>
                <div className="admin-grid-3col">
                    <div>
                        <FormSelect
                            label="Supplier"
                            name="supplier_id"
                            formik={formik}
                            placeholder="Choose a supplier…"
                            options={supplierList.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                        />
                        <button
                            type="button"
                            className="admin-variant-image-btn"
                            onClick={() => setAddingSupplier((v) => !v)}
                        >
                            {addingSupplier ? 'Cancel' : '+ New supplier'}
                        </button>
                    </div>

                    <FormInput
                        label="Invoice number"
                        name="invoice_number"
                        formik={formik}
                        placeholder="INV-99321"
                    />

                    <FormInput
                        label="Received on"
                        name="received_on"
                        type="date"
                        formik={formik}
                    />
                </div>

                {addingSupplier && (
                    <div className="admin-supplier-add">
                        <FormInput
                            label="Supplier name"
                            name="name"
                            formik={supplierForm}
                            placeholder="Star Tech Ltd"
                        />
                        <FormInput
                            label="Phone"
                            name="phone"
                            formik={supplierForm}
                            placeholder="Optional"
                        />
                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={supplierForm.isSubmitting}
                            onClick={supplierForm.handleSubmit}
                        >
                            Add supplier
                        </Button>
                    </div>
                )}

                <div className="admin-receive-lines">
                    {lines.map((line) => (
                        <div className="admin-receive-line" key={line.key}>
                            <div className="admin-receive-line-product">
                                <SearchableSelect
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
