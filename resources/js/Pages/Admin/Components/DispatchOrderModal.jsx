import React, { useEffect } from 'react';
import { useFormik } from 'formik';
import { Truck } from 'lucide-react';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminDispatchSchema } from '@/validations';

/**
 * Handing a parcel to a carrier.
 *
 * Its own step rather than a status change, because marking an order shipped
 * without recording who took it is what left customers ringing up with a
 * question nobody could answer.
 */
export default function DispatchOrderModal({
    order,
    couriers = [],
    onClose,
    onDone,
}) {
    const formik = useFormik({
        initialValues: { courier_id: '', tracking_number: '' },
        validationSchema: adminDispatchSchema,
        onSubmit: async (values, { setSubmitting }) => {
            try {
                await adminService.dispatchOrder(order.id, {
                    ...values,
                    tracking_number: values.tracking_number || null,
                });
                toast.success(`Order #${order.order_number} is on its way.`);
                onDone?.();
            } catch (err) {
                toast.error(err?.message || 'Could not dispatch that order.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    // Re-dispatching an order should show what it already went out with.
    useEffect(() => {
        if (!order) return;

        formik.resetForm({
            values: {
                courier_id: order.courier_id ? String(order.courier_id) : '',
                tracking_number: order.tracking_number || '',
            },
        });
        // Only when the order being dispatched changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order?.id]);

    if (!order) return null;

    return (
        <Modal
            isOpen={Boolean(order)}
            onClose={onClose}
            title={`Dispatch #${order.order_number}`}
            maxWidth="520px"
            footer={
                <div className="admin-input-row-flex admin-modal-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        icon={Truck}
                        onClick={formik.handleSubmit}
                        disabled={formik.isSubmitting}
                    >
                        {formik.isSubmitting
                            ? 'Dispatching…'
                            : 'Mark as shipped'}
                    </Button>
                </div>
            }
        >
            <form onSubmit={formik.handleSubmit}>
                <FormSelect
                    label="Courier"
                    name="courier_id"
                    formik={formik}
                    required
                    options={[
                        { value: '', label: 'Choose a courier…' },
                        ...couriers.map((c) => ({
                            value: String(c.id),
                            label: c.phone ? `${c.name} — ${c.phone}` : c.name,
                        })),
                    ]}
                />

                <FormInput
                    label="Consignment number"
                    name="tracking_number"
                    formik={formik}
                    placeholder="Optional"
                />
                <span className="admin-field-hint">
                    Leave blank for your own rider, or a courier that issues no
                    number. The customer sees whatever is entered here, and a
                    tracking link when the courier has one.
                </span>
            </form>
        </Modal>
    );
}
