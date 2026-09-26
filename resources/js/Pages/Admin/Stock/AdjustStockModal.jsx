import React from 'react';
import { useFormik } from 'formik';
import Button from '../../../Components/Button';
import FormInput from '../../../Components/FormInput';
import Select from '../../../Components/Select';
import Modal from '../../../Components/Modal';
import { toast } from '../../../Components/Toast';
import { adminService } from '../../../services';
import { adminStockAdjustmentSchema } from '../../../validations';

/**
 * Correcting a count: breakage, loss, or a count that disagrees.
 *
 * Deliberately asks for the change and a reason rather than a new total. Typing
 * an absolute number is how sold units used to come back to life; a signed
 * change against the live balance cannot do that.
 *
 * At one branch, named here. It had no branch choice at all: every correction
 * landed on the primary branch, and was checked against the whole shop's
 * count, so a branch could be taken below zero without a word.
 */
const primaryOf = (stores) =>
    String(stores.find((s) => s.fulfils_online)?.id ?? stores[0]?.id ?? '');

export default function AdjustStockModal({
    target,
    reasons = {},
    stores = [],
    onClose,
    onSaved,
}) {
    const product = target?.product;
    const variant = target?.variant;
    const levels = target?.levels ?? {};

    /*
     * Where to start: the branch holding most of it, which is where a wrong
     * count usually is; the primary branch when nobody holds any.
     */
    const startBranch = () => {
        const held = Object.entries(levels).filter(([, q]) => q !== 0);
        if (held.length === 0) return primaryOf(stores);
        held.sort((a, b) => b[1] - a[1]);
        return String(held[0][0]);
    };

    const formik = useFormik({
        initialValues: {
            store_id: '',
            quantity: '',
            reason: 'stock_take',
            note: '',
        },
        validationSchema: adminStockAdjustmentSchema,
        onSubmit: async (values, { setSubmitting, setFieldError }) => {
            const delta = Number(values.quantity);
            const here = Number(levels[values.store_id] ?? 0);

            if (here + delta < 0) {
                setFieldError(
                    'quantity',
                    `${branchName} has only ${Math.max(0, here)} — that would go below zero.`,
                );
                setSubmitting(false);

                return;
            }

            try {
                await adminService.adjustStock({
                    product_id: product.id,
                    product_variant_id: variant?.id ?? null,
                    store_id: values.store_id ? Number(values.store_id) : null,
                    quantity: delta,
                    reason: values.reason,
                    note: values.note || null,
                });
                toast.success(
                    `${branchName} now has ${here + delta}.`,
                    'Stock corrected',
                );
                onSaved?.();
            } catch (err) {
                toast.error(err?.message || 'Could not correct the stock.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const targetKey = `${product?.id ?? ''}:${variant?.id ?? ''}`;
    const lastKey = React.useRef(null);

    React.useEffect(() => {
        if (target && lastKey.current !== targetKey) {
            lastKey.current = targetKey;
            formik.resetForm({
                values: {
                    store_id: startBranch(),
                    quantity: '',
                    reason: 'stock_take',
                    note: '',
                },
            });
        }

        if (!target) lastKey.current = null;
        // Reset on a new target only; formik and the helpers change identity.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target, targetKey]);

    const branchName =
        stores.find((s) => String(s.id) === String(formik.values.store_id))
            ?.name ?? 'This branch';
    const here = Number(levels[formik.values.store_id] ?? 0);
    const delta = Number(formik.values.quantity) || 0;
    const projected = here + delta;

    return (
        <Modal
            isOpen={Boolean(target)}
            onClose={onClose}
            title="Correct stock"
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
                        {formik.isSubmitting ? 'Saving…' : 'Save correction'}
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
                        {branchName} has {here} now
                    </div>
                </div>

                {stores.length > 1 && (
                    <Select
                        id="adjust-branch"
                        label="Which branch"
                        name="store_id"
                        required
                        formik={formik}
                        options={stores.map((s) => ({
                            value: String(s.id),
                            label: `${s.name} — has ${levels[s.id] ?? 0}`,
                        }))}
                    />
                )}

                <FormInput
                    id="adjust-quantity"
                    label="Add or remove"
                    name="quantity"
                    required
                    type="number"
                    formik={formik}
                    placeholder="e.g. -2 to remove two, 3 to add three"
                    helperText="Use a minus sign to remove."
                />

                <Select
                    id="adjust-reason"
                    label="Why"
                    name="reason"
                    required
                    formik={formik}
                    options={Object.entries(reasons).map(([value, label]) => ({
                        value,
                        label,
                    }))}
                />

                <FormInput
                    id="adjust-note"
                    label={
                        formik.values.reason === 'other'
                            ? 'Note (required)'
                            : 'Note (optional)'
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
                            ? `${branchName} has only ${Math.max(0, here)} — this would go below zero.`
                            : `${branchName} will have ${projected}.`}
                    </div>
                )}
            </form>
        </Modal>
    );
}
