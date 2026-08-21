import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import {
    Button,
    DataTable,
    FormInput,
    FormSelect,
    Modal,
    Checkbox,
    toast,
} from '../../Components';
import { adminService } from '../../services';
import { adminStoreSchema } from '../../validations';
import { MapPin, Plus, Edit2, Trash2, Phone, Mail, Clock } from 'lucide-react';

export default function AdminStores({ stores = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingStore, setEditingStore] = useState(null);

    const formik = useFormik({
        initialValues: {
            name: '',
            branch_type: 'Flagship Experience Center',
            city: 'Dhaka',
            address: '',
            phone: '',
            email: '',
            opening_hours: '10:00 AM - 8:00 PM (Closed on Friday)',
            is_active: true,
        },
        validationSchema: adminStoreSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editingStore) {
                    await adminService.updateStore(editingStore.id, values);
                    toast.success('Branch outlet updated successfully!');
                } else {
                    await adminService.createStore(values);
                    toast.success('Branch outlet added successfully!');
                }
                setModalOpen(false);
                setEditingStore(null);
                resetForm();
                router.reload({ only: ['stores'] });
            } catch (err) {
                toast.error(err?.message || 'Failed to save store.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleOpenCreate = () => {
        setEditingStore(null);
        formik.resetForm({
            values: {
                name: '',
                branch_type: 'Flagship Experience Center',
                city: 'Dhaka',
                address: '',
                phone: '',
                email: '',
                opening_hours: '10:00 AM - 8:00 PM (Closed on Friday)',
                is_active: true,
            },
        });
        setModalOpen(true);
    };

    const handleOpenEdit = (store) => {
        setEditingStore(store);
        formik.resetForm({
            values: {
                name: store.name || '',
                branch_type: store.branch_type || 'Flagship Experience Center',
                city: store.city || 'Dhaka',
                address: store.address || '',
                phone: store.phone || '',
                email: store.email || '',
                opening_hours:
                    store.opening_hours ||
                    '10:00 AM - 8:00 PM (Closed on Friday)',
                is_active: Boolean(store.is_active),
            },
        });
        setModalOpen(true);
    };

    const handleDelete = async (id) => {
        if (!confirm('Are you sure you want to remove this branch?')) return;
        try {
            await adminService.deleteStore(id);
            toast.success('Branch removed.');
            router.reload({ only: ['stores'] });
        } catch (err) {
            toast.error('Failed to delete store.');
        }
    };

    // Columns Definition for Reusable DataTable (SSOT)
    const columns = [
        {
            key: 'name',
            header: 'Branch Name',
            render: (row) => (
                <div>
                    <strong className="admin-table-title-bold">
                        {row.name}
                    </strong>
                    <span className="admin-table-desc-sub">
                        {row.branch_type}
                    </span>
                </div>
            ),
        },
        {
            key: 'city',
            header: 'City / Region',
            render: (row) => <span className="font-semibold">{row.city}</span>,
        },
        {
            key: 'address',
            header: 'Address',
            render: (row) => (
                <span className="admin-table-desc-sub">{row.address}</span>
            ),
        },
        {
            key: 'phone',
            header: 'Phone Contact',
            render: (row) => (
                <div className="admin-input-row-flex">
                    <Phone size={13} className="text-primary" />
                    <span>{row.phone}</span>
                </div>
            ),
        },
        {
            key: 'opening_hours',
            header: 'Hours',
            render: (row) => (
                <div className="admin-input-row-flex">
                    <Clock size={13} className="text-muted" />
                    <span className="text-sm">{row.opening_hours}</span>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <span
                    className={`status-pill ${row.is_active ? 'active' : 'inactive'}`}
                >
                    {row.is_active ? 'Active' : 'Closed'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (row) => (
                <div
                    className="admin-input-row-flex"
                    style={{ justifyContent: 'flex-end', gap: '8px' }}
                >
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={Edit2}
                        onClick={() => handleOpenEdit(row)}
                    >
                        Edit
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        icon={Trash2}
                        onClick={() => handleDelete(row.id)}
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Showroom Outlets &amp; Branches"
            subtitle="Manage offline flagship stores, express pickup locations, and service hubs"
        >
            <Head title="Admin Stores &amp; Outlets — Robin IT" />

            <div className="admin-page-container">
                {/* Reusable DataTable Component with Header Actions */}
                <DataTable
                    title="Active Storefront Outlets"
                    subtitle="Locations displayed on the customer Store Locator page and Checkout Pickup selector."
                    columns={columns}
                    data={stores}
                    searchable
                    searchPlaceholder="Search branches by name, city, address..."
                    emptyTitle="No Store Locations Added"
                    emptyDescription="You haven't configured any physical showroom outlets yet."
                    emptyIcon={MapPin}
                    emptyActionText="Add First Showroom"
                    onEmptyAction={handleOpenCreate}
                    headerActions={
                        <Button
                            variant="primary"
                            icon={Plus}
                            onClick={handleOpenCreate}
                        >
                            Add New Branch
                        </Button>
                    }
                />

                {/* Unified Create / Edit Modal (Single SSOT Form) */}
                <Modal
                    isOpen={modalOpen}
                    onClose={() => {
                        setModalOpen(false);
                        setEditingStore(null);
                    }}
                    title={
                        editingStore
                            ? `Edit Branch: ${editingStore.name}`
                            : 'Add Showroom Outlet'
                    }
                    maxWidth="580px"
                >
                    <form onSubmit={formik.handleSubmit}>
                        <div className="admin-form-stack">
                            <FormInput
                                label="Branch Outlet Name"
                                name="name"
                                required
                                formik={formik}
                                placeholder="e.g. IDB Bhaban Flagship Showroom"
                            />

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="City / Division"
                                    name="city"
                                    required
                                    formik={formik}
                                    placeholder="Dhaka"
                                />

                                <FormSelect
                                    label="Branch Type"
                                    name="branch_type"
                                    formik={formik}
                                    options={[
                                        {
                                            value: 'Flagship Experience Center',
                                            label: 'Flagship Experience Center',
                                        },
                                        {
                                            value: 'Showroom & PC Assembly',
                                            label: 'Showroom & PC Assembly',
                                        },
                                        {
                                            value: 'Official Service Center',
                                            label: 'Official Service Center',
                                        },
                                        {
                                            value: 'Express Outlet',
                                            label: 'Express Outlet',
                                        },
                                    ]}
                                />
                            </div>

                            <FormInput
                                label="Full Address"
                                name="address"
                                type="textarea"
                                rows={2}
                                required
                                formik={formik}
                                placeholder="Shop #301-304, Level 3, IDB Bhaban, Agargaon, Dhaka"
                            />

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Contact Phone"
                                    name="phone"
                                    required
                                    formik={formik}
                                    placeholder="01712-345678"
                                />
                                <FormInput
                                    label="Branch Email"
                                    name="email"
                                    type="email"
                                    formik={formik}
                                    placeholder="idb@robinscomputer.com"
                                />
                            </div>

                            <FormInput
                                label="Opening Hours"
                                name="opening_hours"
                                required
                                formik={formik}
                                placeholder="10:00 AM - 8:00 PM (Closed on Friday)"
                            />

                            <div>
                                <Checkbox
                                    name="is_active"
                                    label="Active in Store Locator and Checkout Pickup"
                                    checked={formik.values.is_active}
                                    onChange={formik.handleChange}
                                />
                            </div>

                            <div className="admin-modal-action-row">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        setModalOpen(false);
                                        setEditingStore(null);
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={formik.isSubmitting}
                                >
                                    {editingStore
                                        ? 'Update Branch'
                                        : 'Save Branch'}
                                </Button>
                            </div>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
