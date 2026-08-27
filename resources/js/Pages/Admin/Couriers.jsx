import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { Truck, Plus, Edit2, Trash2 } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminCourierSchema } from '@/validations';

const empty = {
    name: '',
    tracking_url_template: '',
    phone: '',
    note: '',
};

/**
 * The delivery companies the shop hands parcels to.
 *
 * Seeded with the carriers most Bangladeshi shops use, and editable, because
 * carriers change their tracking URLs and correcting one should not need a
 * deploy.
 */
export default function AdminCouriers({
    couriers = [],
    placeholder = '{tracking}',
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const formik = useFormik({
        initialValues: empty,
        validationSchema: adminCourierSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editing) {
                    await adminService.updateCourier(editing.id, values);
                    toast.success(`"${values.name}" updated.`);
                } else {
                    await adminService.createCourier(values);
                    toast.success(`"${values.name}" added.`);
                }

                setModalOpen(false);
                setEditing(null);
                resetForm({ values: empty });
                router.reload({ only: ['couriers'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that courier.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: empty });
        setModalOpen(true);
    };

    const openEdit = (courier) => {
        setEditing(courier);
        formik.resetForm({
            values: {
                name: courier.name || '',
                tracking_url_template: courier.tracking_url_template || '',
                phone: courier.phone || '',
                note: courier.note || '',
            },
        });
        setModalOpen(true);
    };

    const remove = async (courier) => {
        const warning = courier.orders_count
            ? `"${courier.name}" has carried ${courier.orders_count} order(s). It will be hidden rather than deleted so those orders can still say who took them. Continue?`
            : `Remove "${courier.name}"?`;

        if (!confirm(warning)) return;

        try {
            const res = await adminService.deleteCourier(courier.id);
            toast.success(
                res?.name ? `"${res.name}" hidden.` : 'Courier removed.',
            );
            router.reload({ only: ['couriers'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that courier.');
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Courier',
            render: (c) => (
                <div>
                    <div className="admin-stock-product-name">
                        {c.name}
                        {!c.is_active && (
                            <span className="admin-supplier-retired">
                                Hidden
                            </span>
                        )}
                    </div>
                    {(c.phone || c.note) && (
                        <div className="admin-field-hint">
                            {[c.phone, c.note].filter(Boolean).join(' · ')}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'tracking',
            header: 'Tracking link',
            render: (c) =>
                c.tracking_url_template ? (
                    <code className="admin-field-hint">
                        {c.tracking_url_template}
                    </code>
                ) : (
                    // Not a gap to be filled in: several carriers genuinely
                    // have no per-parcel page, and the number is still recorded.
                    <span className="admin-field-hint">
                        No public lookup — number recorded only
                    </span>
                ),
        },
        {
            key: 'orders',
            header: 'Parcels',
            render: (c) => c.orders_count ?? 0,
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (c) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Edit courier"
                        onClick={() => openEdit(c)}
                    >
                        <Edit2 size={14} />
                    </button>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Remove courier"
                        onClick={() => remove(c)}
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Couriers"
            subtitle="Who carries the parcels, and where customers track them"
        >
            <Head title="Couriers" />

            <DataTable
                columns={columns}
                data={couriers}
                title="Couriers"
                subtitle="Offered when dispatching an order"
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        Add courier
                    </Button>
                }
                emptyTitle="No couriers yet"
                emptyDescription="Add the delivery companies the shop hands parcels to."
                emptyIcon={Truck}
                emptyActionText="Add courier"
                onEmptyAction={openCreate}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add courier'}
                maxWidth="620px"
                footer={
                    <div className="admin-input-row-flex admin-modal-actions">
                        <Button
                            variant="secondary"
                            onClick={() => setModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={formik.handleSubmit}
                            disabled={formik.isSubmitting}
                        >
                            {formik.isSubmitting
                                ? 'Saving…'
                                : editing
                                  ? 'Save changes'
                                  : 'Add courier'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit}>
                    <FormInput
                        label="Name"
                        name="name"
                        formik={formik}
                        required
                        placeholder="Pathao Courier"
                    />

                    <FormInput
                        label="Tracking link"
                        name="tracking_url_template"
                        formik={formik}
                        placeholder={`https://example.com/track/${placeholder}`}
                    />
                    <span className="admin-field-hint">
                        Put <code>{placeholder}</code> where the consignment
                        number goes. Leave blank if the courier has no public
                        lookup — the number is still recorded and printed. Worth
                        checking against the merchant panel, since carriers
                        change these.
                    </span>

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Phone"
                            name="phone"
                            formik={formik}
                            placeholder="09678100800"
                        />
                        <FormInput
                            label="Note"
                            name="note"
                            formik={formik}
                            placeholder="Optional"
                        />
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
