import React, { useEffect, useState } from 'react';
import { Button, FormInput, Modal, toast } from '../../../Components';
import { adminService } from '../../../services';

/**
 * A counted correction: breakage, loss, or a stock-take that disagrees.
 *
 * Deliberately asks for the change and a reason rather than a new total. Typing
 * an absolute number is how sold units used to come back to life; a signed
 * change against the live balance cannot do that.
 */
export default function AdjustStockModal({
    target,
    reasons = {},
    onClose,
    onSaved,
}) {
    const [quantity, setQuantity] = useState('');
    const [reason, setReason] = useState('stock_take');
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);

    const product = target?.product;
    const variant = target?.variant;
    const onHand = variant
        ? variant.stock_quantity
        : (product?.stock_quantity ?? 0);
    const delta = Number(quantity) || 0;
    const projected = onHand + delta;

    useEffect(() => {
        if (target) {
            setQuantity('');
            setReason('stock_take');
            setNote('');
        }
    }, [target]);

    const submit = async () => {
        if (!delta) {
            toast.error('Enter how many units to add or remove.');
            return;
        }

        if (projected < 0) {
            toast.error(`Only ${onHand} on hand — that would go below zero.`);
            return;
        }

        if (reason === 'other' && !note.trim()) {
            toast.error('Explain the adjustment in the note.');
            return;
        }

        setSaving(true);

        try {
            const movement = await adminService.adjustStock({
                product_id: product.id,
                product_variant_id: variant?.id ?? null,
                quantity: delta,
                reason,
                note: note || null,
            });
            toast.success(
                `Stock adjusted to ${movement?.balance_after ?? projected}.`,
            );
            onSaved?.();
        } catch (err) {
            toast.error(err?.message || 'Could not adjust stock.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(target)}
            onClose={onClose}
            title="Adjust stock"
            maxWidth="520px"
            footer={
                <div className="admin-input-row-flex admin-modal-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={saving || !delta}>
                        {saving ? 'Recording…' : 'Record adjustment'}
                    </Button>
                </div>
            }
        >
            <div className="admin-adjust-summary">
                <div className="admin-adjust-name">
                    {product?.name}
                    {variant && <span> — {variant.name}</span>}
                </div>
                <div className="admin-field-hint">
                    Currently {onHand} on hand
                </div>
            </div>

            <FormInput
                label="Change"
                type="number"
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                placeholder="e.g. -2 to remove two, 3 to add three"
            />

            <div className="form-group">
                <label className="form-label">Reason</label>
                <select
                    className="form-input"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                >
                    {Object.entries(reasons).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>

            <FormInput
                label={reason === 'other' ? 'Note (required)' : 'Note'}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="What happened?"
            />

            {Boolean(delta) && (
                <div
                    className={`admin-adjust-projection ${
                        projected < 0 ? 'admin-adjust-projection-bad' : ''
                    }`}
                >
                    {projected < 0
                        ? `Only ${onHand} on hand — this would go below zero.`
                        : `New balance will be ${projected}.`}
                </div>
            )}
        </Modal>
    );
}
