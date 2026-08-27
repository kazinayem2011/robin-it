import React from 'react';
import { useFormik } from 'formik';
import Button from '../../../Components/Button';
import FormInput from '../../../Components/FormInput';
import FormSelect from '../../../Components/FormSelect';
import Modal from '../../../Components/Modal';
import { toast } from '../../../Components/Toast';
import { adminService } from '../../../services';
import { adminStockAdjustmentSchema } from '../../../validations';

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
    const product = target?.product;
    const variant = target?.variant;
    const onHand = variant
        ? variant.stock_quantity
        : (product?.stock_quantity ?? 0);

    const formik = useFormik({
        initialValues: { quantity: '', reason: 'stock_take', note: '' },
        validationSchema: adminStockAdjustmentSchema,
        onSubmit: async (values, { setSubmitting, setFieldError }) => {
            const delta = Number(values.quantity);

            // The server refuses this too; catching it here keeps the admin
            // from losing a typed note to a round trip.
            if (onHand + delta < 0) {
                setFieldError(
                    'quantity',
                    `Only ${onHand} on hand — that would go below zero.`,
                );
                setSubmitting(false);

                return;
            }

            try {
                const movement = await adminService.adjustStock({
                    product_id: product.id,
                    product_variant_id: variant?.id ?? null,
                    quantity: delta,
                    reason: values.reason,
                    note: values.note || null,
                });
                toast.success(
                    `Stock adjusted to ${movement?.balance_after ?? onHand + delta}.`,
                );
                onSaved?.();
            } catch (err) {
                toast.error(err?.message || 'Could not adjust stock.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    // Reset whenever a different unit is opened, without enableReinitialize:
    // paired with resetForm it fights back and blanks the form.
    const targetKey = `${product?.id ?? ''}:${variant?.id ?? ''}`;
    const lastKey = React.useRef(null);

    React.useEffect(() => {
        if (target && lastKey.current !== targetKey) {
            lastKey.current = targetKey;
            formik.resetForm({
                values: { quantity: '', reason: 'stock_take', note: '' },
            });
        }

        if (!target) lastKey.current = null;
        // formik is stable enough here; re-running on its identity would loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target, targetKey]);

    const delta = Number(formik.values.quantity) || 0;
    const projected = onHand + delta;

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
                    <Button
                        onClick={formik.handleSubmit}
                        disabled={formik.isSubmitting || !delta}
                    >
                        {formik.isSubmitting
                            ? 'Recording…'
                            : 'Record adjustment'}
                    </Button>
                </div>
            }
        >
            <form onSubmit={formik.handleSubmit} noValidate>
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
                    name="quantity"
                    type="number"
                    formik={formik}
                    placeholder="e.g. -2 to remove two, 3 to add three"
                />

                <FormSelect
                    label="Reason"
                    name="reason"
                    formik={formik}
                    options={Object.entries(reasons).map(([value, label]) => ({
                        value,
                        label,
                    }))}
                />

                <FormInput
                    label={
                        formik.values.reason === 'other'
                            ? 'Note (required)'
                            : 'Note'
                    }
                    name="note"
                    formik={formik}
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
            </form>
        </Modal>
    );
}
