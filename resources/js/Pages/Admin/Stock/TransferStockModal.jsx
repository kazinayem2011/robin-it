import React, { useEffect, useMemo, useState } from 'react';
import { useFormik } from 'formik';
import * as Yup from 'yup';
import Button from '../../../Components/Button';
import FormInput from '../../../Components/FormInput';
import Select from '../../../Components/Select';
import Modal from '../../../Components/Modal';
import { toast } from '../../../Components/Toast';
import { adminService } from '../../../services';

const schema = Yup.object().shape({
    from_store_id: Yup.string().required('Choose where the units are now'),
    to_store_id: Yup.string()
        .required('Choose where they are going')
        .test(
            'different',
            'Choose two different branches',
            (value, ctx) => !value || value !== ctx.parent.from_store_id,
        ),
    quantity: Yup.number()
        .typeError('Enter how many units to move')
        .integer('Enter a whole number of units')
        .min(1, 'Move at least one unit')
        .required('Enter how many units to move'),
    note: Yup.string().max(1000).nullable(),
});

/**
 * Moving units between branches.
 *
 * Written as a pair of movements that cancel out, so the shop's total holding
 * never changes — this only answers where things are, not how many there are.
 */
export default function TransferStockModal({
    target,
    stores = [],
    onClose,
    onSaved,
}) {
    const [breakdown, setBreakdown] = useState([]);

    const product = target?.product;
    const variant = target?.variant;
    const productId = product?.id ?? null;
    const variantId = variant?.id ?? null;

    const formik = useFormik({
        initialValues: {
            from_store_id: '',
            to_store_id: '',
            quantity: '',
            note: '',
        },
        validationSchema: schema,
        onSubmit: async (values, { setSubmitting, setFieldError }) => {
            const held = heldAt(values.from_store_id);

            // The server refuses this too; catching it here keeps a typed note
            // from being lost to a round trip.
            if (Number(values.quantity) > held) {
                setFieldError('quantity', `That branch only holds ${held}.`);
                setSubmitting(false);

                return;
            }

            try {
                await adminService.transferStock({
                    product_id: productId,
                    product_variant_id: variantId,
                    quantity: Number(values.quantity),
                    from_store_id: Number(values.from_store_id),
                    to_store_id: Number(values.to_store_id),
                    note: values.note || null,
                });
                toast.success('Stock transferred.');
                onSaved?.();
            } catch (err) {
                toast.error(err?.message || 'Could not transfer that stock.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    useEffect(() => {
        if (!productId) return;

        let cancelled = false;
        formik.resetForm();

        adminService
            .getStockBranches(productId, variantId)
            .then((rows) => {
                if (cancelled) return;
                const list = Array.isArray(rows) ? rows : [];
                setBreakdown(list);

                // Start from the branch holding most, which is usually where
                // stock is being sent from.
                const most = [...list].sort(
                    (a, b) => b.quantity - a.quantity,
                )[0];
                if (most && most.quantity > 0) {
                    formik.setFieldValue(
                        'from_store_id',
                        String(most.store_id),
                    );
                }
            })
            .catch(() => {
                if (!cancelled) setBreakdown([]);
            });

        return () => {
            cancelled = true;
        };
        // Resetting on the unit alone; formik's identity would loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [productId, variantId]);

    const heldAt = (storeId) =>
        breakdown.find((b) => String(b.store_id) === String(storeId))
            ?.quantity ?? 0;

    // Each branch with what it holds, so nobody has to remember it.
    const fromOptions = useMemo(
        () =>
            stores.map((s) => ({
                value: String(s.id),
                label: `${s.name} — has ${heldAt(s.id)}`,
                disabled: heldAt(s.id) <= 0,
            })),
        // heldAt reads breakdown.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [stores, breakdown],
    );

    const toOptions = useMemo(
        () =>
            stores
                .filter(
                    (s) => String(s.id) !== String(formik.values.from_store_id),
                )
                .map((s) => ({
                    value: String(s.id),
                    label: `${s.name}${s.fulfils_online ? ' (primary)' : ''}`,
                })),
        [stores, formik.values.from_store_id],
    );

    return (
        <Modal
            isOpen={Boolean(target)}
            onClose={onClose}
            title="Transfer stock between branches"
            maxWidth="620px"
            footer={
                <div className="admin-input-row-flex admin-modal-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={formik.handleSubmit}
                        disabled={formik.isSubmitting}
                    >
                        {formik.isSubmitting ? 'Transferring…' : 'Transfer'}
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
                        {breakdown.length === 0
                            ? 'Not held at any branch yet.'
                            : breakdown
                                  .map((b) => `${b.store}: ${b.quantity}`)
                                  .join(' · ')}
                    </div>
                </div>

                <div className="admin-grid-equal-2col">
                    <Select
                        label="From"
                        name="from_store_id"
                        formik={formik}
                        placeholder="Where it is now…"
                        options={fromOptions}
                    />
                    <Select
                        label="To"
                        name="to_store_id"
                        formik={formik}
                        placeholder="Where it's going…"
                        options={toOptions}
                    />
                </div>

                <FormInput
                    label="How many"
                    name="quantity"
                    type="number"
                    min="1"
                    formik={formik}
                    helperText={
                        formik.values.from_store_id
                            ? `${heldAt(formik.values.from_store_id)} there to send`
                            : undefined
                    }
                />

                <FormInput
                    label="Note (optional)"
                    name="note"
                    formik={formik}
                    placeholder="Why is it moving?"
                />
            </form>
        </Modal>
    );
}
