import React, { useEffect } from 'react';
import { localToday } from '@/utils/localToday';
import { useFormik } from 'formik';
import { Undo2 } from 'lucide-react';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import Select from '@/Components/Select';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminRefundSchema } from '@/validations';
import { formatBdt } from '@/utils/formatters';

const today = localToday;

/**
 * Money given back on an order.
 *
 * Separate from processing a return: the two usually happen together and
 * sometimes do not — a damaged item may be refunded without coming back, and
 * an exchange returns goods without any money moving.
 */
export default function RefundOrderModal({
    order,
    methods = [],
    reasons = [],
    onClose,
    onDone,
}) {
    // What is left, so the form can offer it and refuse more: the money
    // received, less what has gone back. It was the order total, so an order
    // nobody had paid for could be "refunded".
    const alreadyGiven = (order?.refunds || []).reduce(
        (sum, r) => sum + Number(r.amount || 0),
        0,
    );
    const received = (order?.payments || []).reduce(
        (sum, p) => sum + Number(p.amount || 0),
        0,
    );
    const remaining =
        order?.refundable_amount !== undefined
            ? Number(order.refundable_amount)
            : Math.max(0, received - alreadyGiven);
    // "Cash never collected" is not a refund: nothing came in to give back.
    const choices = methods.filter((m) => m.value !== 'cod_not_collected');

    const formik = useFormik({
        initialValues: {
            amount: '',
            method: 'bkash',
            reason: 'returned',
            reference: '',
            note: '',
            refunded_on: today(),
        },
        validationSchema: adminRefundSchema,
        onSubmit: async (values, { setSubmitting }) => {
            try {
                await adminService.refundOrder(order.id, values);
                toast.success(
                    `${formatBdt(values.amount)} refunded on #${order.order_number}.`,
                );
                onDone?.();
            } catch (err) {
                toast.error(err?.message || 'Could not record that refund.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    useEffect(() => {
        if (!order) return;

        formik.resetForm({
            values: {
                amount: remaining || '',
                method: 'bkash',
                reason: 'returned',
                reference: '',
                note: '',
                refunded_on: today(),
            },
        });
        // Only when the order being refunded changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order?.id]);

    if (!order) return null;

    return (
        <Modal
            isOpen={Boolean(order)}
            onClose={onClose}
            title={`Refund #${order.order_number}`}
            maxWidth="560px"
            footer={
                <div className="admin-input-row-flex admin-modal-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        icon={Undo2}
                        onClick={formik.handleSubmit}
                        disabled={formik.isSubmitting || remaining <= 0}
                    >
                        {formik.isSubmitting ? 'Recording…' : 'Record refund'}
                    </Button>
                </div>
            }
        >
            <div className="refund-summary">
                <div>
                    <span>Received</span>
                    <strong>{formatBdt(received)}</strong>
                </div>
                {alreadyGiven > 0 && (
                    <div>
                        <span>Already refunded</span>
                        <strong>{formatBdt(alreadyGiven)}</strong>
                    </div>
                )}
                <div className="is-remaining">
                    <span>Can give back</span>
                    <strong>{formatBdt(remaining)}</strong>
                </div>
            </div>

            {remaining <= 0 ? (
                <p className="admin-field-hint">
                    {received > 0
                        ? 'Everything received on this order has already been given back.'
                        : 'Nothing has been received on this order, so there is nothing to give back.'}
                </p>
            ) : (
                <form onSubmit={formik.handleSubmit} noValidate>
                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Amount (৳ BDT)"
                            name="amount"
                            type="number"
                            formik={formik}
                            required
                        />
                        <FormInput
                            label="Date refunded"
                            name="refunded_on"
                            type="date"
                            formik={formik}
                            required
                        />
                    </div>

                    <div className="admin-grid-equal-2col">
                        <Select
                            label="How it went back"
                            name="method"
                            formik={formik}
                            required
                            options={choices}
                        />
                        <Select
                            label="Why"
                            name="reason"
                            formik={formik}
                            required
                            options={reasons}
                        />
                    </div>
                    {/*
                     * The common case on a cash-on-delivery shop, and easy to
                     * get wrong: the parcel came back before the rider took
                     * anything, so no money moves — but the order still has to
                     * show the customer owes nothing.
                     */}
                    {formik.values.method === 'cod_not_collected' && (
                        <span className="admin-field-hint">
                            Recorded so the order shows nothing owed. No money
                            leaves the business, and it will not appear as a
                            payout in the accounts.
                        </span>
                    )}

                    <FormInput
                        label="Reference"
                        name="reference"
                        formik={formik}
                        placeholder="bKash transaction id, bank reference, receipt number"
                    />

                    <FormInput
                        label="Note"
                        name="note"
                        formik={formik}
                        placeholder="Anything worth remembering about this refund"
                    />
                </form>
            )}
        </Modal>
    );
}
