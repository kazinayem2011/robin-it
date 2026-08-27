import React, { useCallback, useState } from 'react';
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
import { adminSupplierSchema } from '@/validations';
import { formatBdt } from '@/utils/formatters';

const emptySupplier = {
    name: '',
    contact_name: '',
    phone: '',
    email: '',
    address: '',
    note: '',
};

/**
 * Who the shop buys stock from.
 *
 * Its own section rather than a corner of the stock screen: this is a standing
 * list to be maintained, and it is where you look when a batch turns out to be
 * faulty and someone needs calling.
 */
export default function AdminSuppliers({ suppliers = {}, filters = {} }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const formik = useFormik({
        initialValues: emptySupplier,
        validationSchema: adminSupplierSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editing) {
                    await adminService.updateSupplier(editing.id, values);
                    toast.success(`Supplier "${values.name}" updated.`);
                } else {
                    await adminService.createSupplier(values);
                    toast.success(`Supplier "${values.name}" added.`);
                }

                setModalOpen(false);
                setEditing(null);
                resetForm({ values: emptySupplier });
                router.reload({ only: ['suppliers'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that supplier.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: emptySupplier });
        setModalOpen(true);
    };

    const openEdit = (supplier) => {
        setEditing(supplier);
        formik.resetForm({
            values: {
                name: supplier.name || '',
                contact_name: supplier.contact_name || '',
                phone: supplier.phone || '',
                email: supplier.email || '',
                address: supplier.address || '',
                note: supplier.note || '',
            },
        });
        setModalOpen(true);
    };

    const remove = async (supplier) => {
        const warning = supplier.receipts_count
            ? `"${supplier.name}" has ${supplier.receipts_count} delivery(s). It will be deactivated rather than deleted so that history survives. Continue?`
            : `Remove "${supplier.name}"?`;

        if (!confirm(warning)) return;

        try {
            const res = await adminService.deleteSupplier(supplier.id);
            toast.success(
                res?.name ? `"${res.name}" deactivated.` : 'Supplier removed.',
            );
            router.reload({ only: ['suppliers'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that supplier.');
        }
    };

    // Stable identity: an inline arrow re-fires the search effect forever.
    const handleSearch = useCallback((value) => {
        router.get(
            '/admin/suppliers',
            { search: value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['suppliers', 'filters'],
            },
        );
    }, []);

    const columns = [
        {
            key: 'name',
            header: 'Supplier',
            render: (s) => (
                <div>
                    <div className="admin-stock-product-name">
                        {s.name}
                        {!s.is_active && (
                            <span className="admin-supplier-retired">
                                Retired
                            </span>
                        )}
                    </div>
                    {s.address && (
                        <div className="admin-field-hint">{s.address}</div>
                    )}
                </div>
            ),
        },
        {
            key: 'contact',
            header: 'Contact',
            render: (s) => (
                <div>
                    <div>{s.contact_name || '—'}</div>
                    {(s.phone || s.email) && (
                        <div className="admin-field-hint">
                            {[s.phone, s.email].filter(Boolean).join(' · ')}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'deliveries',
            header: 'Deliveries',
            render: (s) => s.receipts_count ?? 0,
        },
        {
            key: 'units',
            header: 'Units received',
            render: (s) => (s.units_received ?? 0).toLocaleString(),
        },
        {
            key: 'spend',
            header: 'Spend',
            render: (s) => (s.total_spend > 0 ? formatBdt(s.total_spend) : '—'),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (s) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Edit supplier"
                        onClick={() => openEdit(s)}
                    >
                        <Edit2 size={14} />
                    </button>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Remove supplier"
                        onClick={() => remove(s)}
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="Suppliers" subtitle="Who the shop buys stock from">
            <Head title="Suppliers" />

            <DataTable
                columns={columns}
                data={suppliers}
                title="Suppliers"
                subtitle="Chosen when recording a delivery"
                searchable
                searchValue={filters.search || ''}
                onSearch={handleSearch}
                searchPlaceholder="Search suppliers..."
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        Add supplier
                    </Button>
                }
                emptyTitle="No suppliers yet"
                emptyDescription="Add the businesses you buy stock from; they can then be picked when recording a delivery."
                emptyIcon={Truck}
                emptyActionText="Add supplier"
                onEmptyAction={openCreate}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add supplier'}
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
                                  : 'Add supplier'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit}>
                    <FormInput
                        label="Supplier name"
                        name="name"
                        formik={formik}
                        required
                        placeholder="Star Tech Ltd"
                    />

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Contact person"
                            name="contact_name"
                            formik={formik}
                            placeholder="Optional"
                        />
                        <FormInput
                            label="Phone"
                            name="phone"
                            formik={formik}
                            placeholder="01711223344"
                        />
                    </div>

                    <FormInput
                        label="Email"
                        name="email"
                        type="email"
                        formik={formik}
                        placeholder="Optional"
                    />

                    <FormInput
                        label="Address"
                        name="address"
                        formik={formik}
                        placeholder="Optional"
                    />

                    <FormInput
                        label="Note"
                        name="note"
                        formik={formik}
                        placeholder="Payment terms, lead time, anything worth remembering"
                    />
                </form>
            </Modal>
        </AdminLayout>
    );
}
