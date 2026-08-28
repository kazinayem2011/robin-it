import React, { useMemo } from 'react';
import { useFormik } from 'formik';
import { Wallet } from 'lucide-react';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';
import { adminOrderPaymentSchema } from '@/validations';

/**
 * Writing down money received against an order.
 *
 * Not a gateway — nothing here charges anybody. It records what was handed
 * over at the counter, or sent by bKash before the parcel went out, so a
 * deposit on a build stops being indistinguishable from having paid nothing.
 */
export default function RecordPaymentModal({
    order,
    methods = [],
    onClose,
    onDone,
}) {
    const paid = useMemo(
        () =>
            (order?.payments || []).reduce(
                (sum, p) => sum + Number(p.amount || 0),
                0,
            ),
        [order],
    );
    const refunded = useMemo(
        () =>
            (order?.refunds || []).reduce(
                (sum, r) => sum + Number(r.amount || 0),
                0,
            ),
        [order],
    );
    const due = Math.max(0, Number(order?.total || 0) - (paid - refunded));

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: {
            // The balance, because taking the rest is the common case.
            amount: due > 0 ? String(due) : '',
            method: 'cash',
            reference: '',
            note: '',
            received_on: new Date().toISOString().slice(0, 10),
        },
        validationSchema: adminOrderPaymentSchema(due),
        onSubmit: async (values, { setSubmitting }) => {
            try {
                const data = await adminService.recordOrderPayment(order.id, {
                    ...values,
                    amount: Number(values.amount),
                });
                toast.success(data?.message || 'Payment recorded.');
                onDone?.();
            } catch (err) {
                toast.error(err?.message || 'Could not record that payment.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    if (!order) return null;

    return (
        <Modal
            isOpen={Boolean(order)}
            onClose={onClose}
            title={`Record payment — ${order.order_number}`}
            maxWidth="560px"
            footer={
                <div className="admin-input-row-flex admin-modal-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        icon={Wallet}
                        onClick={formik.handleSubmit}
                        disabled={formik.isSubmitting || due <= 0}
                    >
                        {formik.isSubmitting ? 'Recording…' : 'Record payment'}
                    </Button>
                </div>
            }
        >
            <div className="pay-summary">
                <div>
                    <span>Order total</span>
                    <strong>{formatBdt(order.total)}</strong>
                </div>
                <div>
                    <span>Received so far</span>
                    <strong>{formatBdt(paid)}</strong>
                </div>
                {refunded > 0 && (
                    <div>
                        <span>Refunded</span>
                        <strong>{formatBdt(refunded)}</strong>
                    </div>
                )}
                <div className={due > 0 ? 'pay-due' : 'pay-settled'}>
                    <span>{due > 0 ? 'Still owed' : 'Settled'}</span>
                    <strong>{formatBdt(due)}</strong>
                </div>
            </div>

            {due <= 0 ? (
                <p className="admin-field-hint">
                    Nothing is outstanding on this order. To give money back,
                    record a refund instead.
                </p>
            ) : (
                <form onSubmit={formik.handleSubmit} noValidate>
                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Amount received"
                            name="amount"
                            type="number"
                            step="0.01"
                            formik={formik}
                            required
                        />
                        <FormSelect
                            label="How"
                            name="method"
                            formik={formik}
                            required
                            options={methods}
                        />
                    </div>

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Reference"
                            name="reference"
                            formik={formik}
                            placeholder="bKash TrxID, slip number…"
                        />
                        <FormInput
                            label="Received on"
                            name="received_on"
                            type="date"
                            formik={formik}
                        />
                    </div>

                    <FormInput
                        label="Note"
                        name="note"
                        formik={formik}
                        placeholder="Advance on a custom build, balance on delivery…"
                    />
                </form>
            )}
        </Modal>
    );
}
