import React, { useEffect, useState } from 'react';
import { Trash2, Plus } from 'lucide-react';
import Modal from '@/Components/Modal';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';

/**
 * Changing what is on an order after it was placed.
 *
 * A customer ringing to add a stick of RAM used to mean cancelling the order
 * and starting again, which lost the order number, the tracking link already
 * texted to them, and any deposit's connection to the order it was paid
 * against.
 *
 * The whole list is sent, not a patch: quantities, additions and removals
 * settle together on the server, so an edit that fails part way cannot leave an
 * order half-changed with stock moved for the half that worked.
 */
export default function EditOrderModal({ order, onClose, onDone }) {
    const [lines, setLines] = useState([]);
    const [reason, setReason] = useState('');
    const [search, setSearch] = useState('');
    const [units, setUnits] = useState([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!order) return;

        setLines(
            (order.items ?? []).map((i) => ({
                key: `existing-${i.id}`,
                order_item_id: i.id,
                name: i.variant_name
                    ? `${i.product_name} (${i.variant_name})`
                    : i.product_name,
                price: Number(i.price),
                quantity: i.quantity,
                original: i.quantity,
            })),
        );
        setReason('');
        setSearch('');
        setUnits([]);
    }, [order]);

    const find = async (term) => {
        setSearch(term);

        if (term.trim().length < 2) {
            setUnits([]);
            return;
        }

        try {
            const res = await adminService.getStockUnits({ search: term });
            setUnits(res?.data ?? res ?? []);
        } catch {
            setUnits([]);
        }
    };

    const add = (product, variant = null) => {
        const key = `new-${product.id}:${variant?.id ?? ''}`;

        if (lines.some((l) => l.key === key)) return;

        setLines((prev) => [
            ...prev,
            {
                key,
                product_id: product.id,
                product_variant_id: variant?.id ?? null,
                name: variant
                    ? `${product.name} (${variant.name})`
                    : product.name,
                // The server prices it at today's price; this is only so the
                // running total on screen is not silently wrong.
                price: Number(
                    variant
                        ? variant.discount_price || variant.price
                        : product.discount_price || product.price,
                ),
                quantity: 1,
                original: 0,
            },
        ]);
        setSearch('');
        setUnits([]);
    };

    const setQuantity = (key, value) =>
        setLines((prev) =>
            prev.map((l) =>
                l.key === key
                    ? { ...l, quantity: Math.max(0, Number(value) || 0) }
                    : l,
            ),
        );

    const goods = lines.reduce((sum, l) => sum + l.price * l.quantity, 0);
    const changed = lines.some((l) => l.quantity !== l.original);

    const save = async () => {
        setSaving(true);

        try {
            const res = await adminService.updateOrderLines(order.id, {
                reason: reason || null,
                lines: lines.map((l) => ({
                    order_item_id: l.order_item_id ?? null,
                    product_id: l.product_id ?? null,
                    product_variant_id: l.product_variant_id ?? null,
                    quantity: l.quantity,
                })),
            });
            toast.success(res?.message || 'Order updated.');
            onDone();
        } catch (err) {
            toast.error(err?.message || 'Could not change that order.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(order)}
            onClose={onClose}
            title={`Edit ${order?.order_number ?? 'order'}`}
            maxWidth="680px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={save}
                        loading={saving}
                        disabled={!changed}
                    >
                        Save changes
                    </Button>
                </>
            }
        >
            <p className="admin-field-hint" style={{ marginBottom: 16 }}>
                Stock is already held for this order, so only the difference
                moves. Setting a line to zero removes it and puts those units
                back on the shelf.
            </p>

            <table className="po-lines">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th className="po-num">Price</th>
                        <th className="po-num">Quantity</th>
                        <th className="po-num">Line</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {lines.map((l) => (
                        <tr
                            key={l.key}
                            className={l.quantity === 0 ? 'is-removed' : ''}
                        >
                            <td>{l.name}</td>
                            <td className="po-num">{formatBdt(l.price)}</td>
                            <td className="po-num">
                                <input
                                    type="number"
                                    min="0"
                                    value={l.quantity}
                                    onChange={(e) =>
                                        setQuantity(l.key, e.target.value)
                                    }
                                />
                            </td>
                            <td className="po-num">
                                {formatBdt(l.price * l.quantity)}
                            </td>
                            <td>
                                <button
                                    type="button"
                                    className="admin-table-icon-btn"
                                    title="Remove this line"
                                    onClick={() => setQuantity(l.key, 0)}
                                >
                                    <Trash2 size={13} />
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr>
                        <td colSpan={3}>Goods</td>
                        <td className="po-num" colSpan={2}>
                            <strong>{formatBdt(goods)}</strong>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <p className="admin-field-hint">
                Delivery, VAT and any promo code are worked out again when this
                is saved, so the total here is the goods only.
            </p>

            <FormInput
                label="Add a product"
                name="edit_search"
                value={search}
                onChange={(e) => find(e.target.value)}
                placeholder="Type part of the name…"
            />

            {units.length > 0 && (
                <div className="po-results">
                    {units.slice(0, 8).map((p) =>
                        p.has_variants && p.variants?.length ? (
                            p.variants
                                .filter((v) => v.is_active)
                                .map((v) => (
                                    <button
                                        key={`${p.id}:${v.id}`}
                                        type="button"
                                        onClick={() => add(p, v)}
                                    >
                                        <Plus size={12} /> {p.name} ({v.name})
                                    </button>
                                ))
                        ) : (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => add(p)}
                            >
                                <Plus size={12} /> {p.name}
                            </button>
                        ),
                    )}
                </div>
            )}

            <FormInput
                label="Why"
                name="edit_reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Customer rang to add RAM"
                helperText="Kept with the order, alongside who made the change and what it did to the bill."
            />
        </Modal>
    );
}
