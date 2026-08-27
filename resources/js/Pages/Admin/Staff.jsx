import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { UserCog, Plus, Edit2, Ban } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import Modal from '@/Components/Modal';
import { Checkbox } from '@/Components/Checkbox';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminStaffSchema } from '@/validations';
import './Staff.css';

const empty = {
    name: '',
    email: '',
    phone: '',
    role: 'storekeeper',
    store_id: '',
    is_active: true,
    password: '',
    password_confirmation: '',
};

/**
 * Who works in the admin, and what their job covers.
 *
 * There were two roles — admin and customer — so anyone let in could do
 * everything: a storekeeper recording a delivery could also read the accounts
 * or change the SMTP password.
 */
export default function AdminStaff({ staff = [], roles = [], stores = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const formik = useFormik({
        initialValues: empty,
        validationSchema: adminStaffSchema(Boolean(editing)),
        enableReinitialize: false,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            const payload = { ...values, store_id: values.store_id || null };

            try {
                if (editing) {
                    await adminService.updateStaff(editing.id, payload);
                    toast.success(`${values.name}'s account updated.`);
                } else {
                    await adminService.createStaff(payload);
                    toast.success(`${values.name} can now sign in.`);
                }

                setModalOpen(false);
                setEditing(null);
                resetForm({ values: empty });
                router.reload({ only: ['staff'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that account.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    // What the chosen role actually covers, so it is not a word to guess at.
    const chosenRole = useMemo(
        () => roles.find((r) => r.value === formik.values.role),
        [roles, formik.values.role],
    );

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: empty });
        setModalOpen(true);
    };

    const openEdit = (member) => {
        setEditing(member);
        formik.resetForm({
            values: {
                name: member.name || '',
                email: member.email || '',
                phone: member.phone || '',
                role: member.role || 'storekeeper',
                store_id: member.store_id ? String(member.store_id) : '',
                is_active: member.is_active !== false,
                // Blank means "leave the password as it is".
                password: '',
                password_confirmation: '',
            },
        });
        setModalOpen(true);
    };

    const suspend = async (member) => {
        if (
            !confirm(
                `Suspend ${member.name}? They keep their account and their name stays on the deliveries and refunds they recorded — they just cannot sign in.`,
            )
        ) {
            return;
        }

        try {
            await adminService.suspendStaff(member.id);
            toast.success(`${member.name}'s access has been suspended.`);
            router.reload({ only: ['staff'] });
        } catch (err) {
            toast.error(err?.message || 'Could not suspend that account.');
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Person',
            render: (m) => (
                <div>
                    <div className="admin-stock-product-name">
                        {m.name}
                        {m.is_self && <span className="staff-you">You</span>}
                        {!m.is_active && (
                            <span className="admin-supplier-retired">
                                Suspended
                            </span>
                        )}
                    </div>
                    <div className="admin-field-hint">
                        {[m.email, m.phone].filter(Boolean).join(' · ')}
                    </div>
                </div>
            ),
        },
        {
            key: 'role',
            header: 'Role',
            render: (m) => m.role_label,
        },
        {
            key: 'store',
            header: 'Branch',
            render: (m) =>
                m.store?.name ?? (
                    <span className="admin-field-hint">Whole shop</span>
                ),
        },
        {
            key: 'seen',
            header: 'Last seen',
            render: (m) =>
                m.last_login_at ?? (
                    <span className="admin-field-hint">Never</span>
                ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (m) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Edit account"
                        onClick={() => openEdit(m)}
                    >
                        <Edit2 size={14} />
                    </button>
                    {/* Suspending yourself would lock you out of your own shop. */}
                    {!m.is_self && m.is_active && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Suspend access"
                            onClick={() => suspend(m)}
                        >
                            <Ban size={14} />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Staff & Roles"
            subtitle="Who works in the admin, and what their job covers"
        >
            <Head title="Staff & Roles" />

            <DataTable
                columns={columns}
                data={staff}
                title="Staff"
                subtitle="Everyone who can sign into the admin"
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        Add staff
                    </Button>
                }
                emptyTitle="No staff yet"
                emptyDescription="Add the people who work in the admin and choose what each of them does."
                emptyIcon={UserCog}
                emptyActionText="Add staff"
                onEmptyAction={openCreate}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add staff'}
                maxWidth="640px"
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
                                  : 'Add staff'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit}>
                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Name"
                            name="name"
                            formik={formik}
                            required
                            placeholder="Karim Uddin"
                        />
                        <FormInput
                            label="Phone"
                            name="phone"
                            formik={formik}
                            placeholder="01712345678"
                        />
                    </div>

                    <FormInput
                        label="Email"
                        name="email"
                        type="email"
                        formik={formik}
                        required
                        placeholder="karim@example.com"
                    />
                    <p className="staff-email-hint admin-field-hint">
                        This is what they sign in with.
                    </p>

                    <div className="admin-grid-equal-2col">
                        <FormSelect
                            label="Role"
                            name="role"
                            formik={formik}
                            required
                            options={roles.map((r) => ({
                                value: r.value,
                                label: r.label,
                            }))}
                        />
                        <FormSelect
                            label="Branch"
                            name="store_id"
                            formik={formik}
                            options={[
                                { value: '', label: 'Whole shop' },
                                ...stores.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                })),
                            ]}
                        />
                    </div>

                    {/* A role is a word until you say what it covers. */}
                    {chosenRole && (
                        <div className="staff-role-summary">
                            <strong>{chosenRole.description}</strong>
                            <div className="staff-role-abilities">
                                {chosenRole.abilities.map((a) => (
                                    <span key={a}>{a}</span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Suspending is done from the table; this is the way back. */}
                    {editing && !editing.is_self && (
                        <Checkbox
                            className="staff-active-toggle"
                            name="is_active"
                            label="Can sign in"
                            checked={formik.values.is_active}
                            onChange={formik.handleChange}
                        />
                    )}

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label={editing ? 'New password' : 'Password'}
                            name="password"
                            type="password"
                            formik={formik}
                            required={!editing}
                            placeholder={editing ? 'Leave blank to keep' : ''}
                        />
                        <FormInput
                            label="Confirm password"
                            name="password_confirmation"
                            type="password"
                            formik={formik}
                            required={!editing}
                        />
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
