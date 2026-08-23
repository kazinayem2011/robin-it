import React, { useEffect, useMemo, useState } from 'react';
import { Button, FormInput, Modal, toast } from '../../../Components';
import { adminService } from '../../../services';
import { formatBdt } from '../../../utils/formatters';
import { Plus, Trash2 } from 'lucide-react';

const blankLine = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    unit: '',
    quantity: '',
    unit_cost: '',
});

/**
 * Booking a delivery from a supplier — the only way units enter the shelf.
 *
 * One receipt covers one invoice and can carry many lines, so a delivery stays
 * a single record rather than a scatter of unexplained quantity changes.
 */
export default function ReceiveStockModal({ isOpen, onClose, onSaved }) {
    const [units, setUnits] = useState([]);
    const [lines, setLines] = useState([blankLine()]);
    const [saving, setSaving] = useState(false);
    const [header, setHeader] = useState({
        supplier_name: '',
        invoice_number: '',
        received_on: new Date().toISOString().slice(0, 10),
        note: '',
    });

    useEffect(() => {
        if (!isOpen) return;

        let cancelled = false;

        adminService
            .getStockUnits()
            .then((res) => {
                // adminService already unwraps the envelope; this is the list.
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
                              onHand: v.stock_quantity,
                          }))
                    : [
                          {
                              value: `${product.id}:`,
                              label: product.name,
                              onHand: product.stock_quantity,
                          },
                      ],
            ),
        [units],
    );

    const setLine = (key, patch) =>
        setLines((prev) =>
            prev.map((l) => (l.key === key ? { ...l, ...patch } : l)),
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

    const reset = () => {
        setLines([blankLine()]);
        setHeader({
            supplier_name: '',
            invoice_number: '',
            received_on: new Date().toISOString().slice(0, 10),
            note: '',
        });
    };

    const submit = async () => {
        const payload = lines
            .filter((l) => l.unit && Number(l.quantity) > 0)
            .map((l) => {
                const [productId, variantId] = l.unit.split(':');

                return {
                    product_id: Number(productId),
                    product_variant_id: variantId ? Number(variantId) : null,
                    quantity: Number(l.quantity),
                    unit_cost: l.unit_cost === '' ? null : Number(l.unit_cost),
                };
            });

        if (payload.length === 0) {
            toast.error('Pick a product and enter how many arrived.');
            return;
        }

        setSaving(true);

        try {
            const receipt = await adminService.receiveStock({
                ...header,
                lines: payload,
            });
            toast.success(
                `Received ${receipt?.total_quantity ?? totals.qty} unit(s)${
                    receipt?.reference ? ` as ${receipt.reference}` : ''
                }.`,
            );
            reset();
            onSaved?.();
        } catch (err) {
            toast.error(err?.message || 'Could not record this delivery.');
        } finally {
            setSaving(false);
        }
    };

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
                        <Button onClick={submit} disabled={saving}>
                            {saving ? 'Recording…' : 'Receive into stock'}
                        </Button>
                    </div>
                </div>
            }
        >
            <div className="admin-grid-3col">
                <FormInput
                    label="Supplier"
                    value={header.supplier_name}
                    onChange={(e) =>
                        setHeader({ ...header, supplier_name: e.target.value })
                    }
                    placeholder="Star Tech Ltd"
                />
                <FormInput
                    label="Invoice number"
                    value={header.invoice_number}
                    onChange={(e) =>
                        setHeader({ ...header, invoice_number: e.target.value })
                    }
                    placeholder="INV-99321"
                />
                <FormInput
                    label="Received on"
                    type="date"
                    value={header.received_on}
                    onChange={(e) =>
                        setHeader({ ...header, received_on: e.target.value })
                    }
                />
            </div>

            <div className="admin-receive-lines">
                {lines.map((line) => {
                    const chosen = options.find((o) => o.value === line.unit);

                    return (
                        <div className="admin-receive-line" key={line.key}>
                            <div className="admin-receive-line-product">
                                <label className="form-label">Product</label>
                                <select
                                    className="form-input"
                                    value={line.unit}
                                    onChange={(e) =>
                                        setLine(line.key, {
                                            unit: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">Choose a product…</option>
                                    {options.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                                {chosen && (
                                    <span className="admin-field-hint">
                                        {chosen.onHand} on hand now
                                    </span>
                                )}
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
                                    setLines((prev) =>
                                        prev.filter((l) => l.key !== line.key),
                                    )
                                }
                            >
                                <Trash2 size={15} />
                            </button>
                        </div>
                    );
                })}
            </div>

            <Button
                variant="secondary"
                size="sm"
                onClick={() => setLines((prev) => [...prev, blankLine()])}
            >
                <Plus size={14} /> Add another line
            </Button>

            <FormInput
                label="Note"
                value={header.note}
                onChange={(e) => setHeader({ ...header, note: e.target.value })}
                placeholder="Anything worth remembering about this delivery"
            />
        </Modal>
    );
}
