import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { ShieldCheck, Plus, Trash2, Lock, Users } from 'lucide-react';
import Button from '@/Components/Button';
import { Checkbox } from '@/Components/Checkbox';
import FormInput from '@/Components/FormInput';
import Modal from '@/Components/Modal';
import EmptyState from '@/Components/EmptyState';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminRoleSchema } from '@/validations';
import './Roles.css';

const empty = { key: '', label: '', description: '', abilities: [] };

/**
 * What each job covers.
 *
 * The roles and their abilities were a constant in the codebase, so a shop
 * that wanted its storekeepers to see the customer directory, or a role of its
 * own for the person who only answers the phone, needed a developer.
 */
export default function AdminRoles({
    roles = [],
    abilities = [],
    ownerKey = 'admin',
    customerKey = 'customer',
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(null);

    const formik = useFormik({
        initialValues: empty,
        validationSchema: adminRoleSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                const data = editing
                    ? await adminService.updateRole(editing.id, values)
                    : await adminService.createRole(values);
                toast.success(data?.message || 'Saved.');
                setModalOpen(false);
                setEditing(null);
                resetForm({ values: empty });
                router.reload({ only: ['roles'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that role.');
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

    const openEdit = (role) => {
        setEditing(role);
        formik.resetForm({
            values: {
                key: role.key,
                label: role.label,
                description: role.description || '',
                abilities: role.abilities || [],
            },
        });
        setModalOpen(true);
    };

    // Ticking a box on the card saves it straight away: opening a modal to
    // change one checkbox is a step for nothing.
    const toggleAbility = async (role, ability, on) => {
        const next = on
            ? [...role.abilities, ability]
            : role.abilities.filter((a) => a !== ability);

        setSaving(`${role.id}:${ability}`);
        try {
            await adminService.updateRole(role.id, {
                key: role.key,
                label: role.label,
                description: role.description || '',
                abilities: next,
            });
            router.reload({ only: ['roles'] });
        } catch (err) {
            toast.error(err?.message || 'Could not change that.');
        } finally {
            setSaving(null);
        }
    };

    const remove = async (role) => {
        if (!confirm(`Remove the "${role.label}" role?`)) return;
        try {
            const data = await adminService.deleteRole(role.id);
            toast.success(data?.message || 'Removed.');
            router.reload({ only: ['roles'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that role.');
        }
    };

    return (
        <AdminLayout title="Roles" subtitle="What each job in the shop covers">
            <Head title="Roles" />

            <div className="roles-head">
                <p className="admin-field-hint">
                    Tick what a role covers and it saves straight away. Anyone
                    holding it sees the change on their next page.
                </p>
                <Button icon={Plus} onClick={openCreate}>
                    New role
                </Button>
            </div>

            {roles.length === 0 ? (
                <EmptyState icon={ShieldCheck} title="No roles yet" />
            ) : (
                <div className="roles-grid">
                    {roles.map((role) => {
                        const isOwner = role.key === ownerKey;
                        const isCustomer = role.key === customerKey;
                        // The owner is every ability by definition, and a shop
                        // that could take them away could lock itself out of
                        // this screen. The customer role is not staff at all.
                        const locked = isOwner || isCustomer;

                        return (
                            <article
                                key={role.id}
                                className={`role-card ${locked ? 'is-locked' : ''}`}
                            >
                                <header className="role-card-head">
                                    <div>
                                        <h2>
                                            {role.label}
                                            {role.is_system && (
                                                <span
                                                    className="role-lock"
                                                    title="Built in"
                                                >
                                                    <Lock size={11} />
                                                </span>
                                            )}
                                        </h2>
                                        <span className="role-people">
                                            <Users size={12} />
                                            {role.people}{' '}
                                            {role.people === 1
                                                ? 'person'
                                                : 'people'}
                                        </span>
                                    </div>
                                    <div className="admin-input-row-flex">
                                        <button
                                            type="button"
                                            className="admin-table-icon-btn"
                                            title="Rename or describe"
                                            onClick={() => openEdit(role)}
                                        >
                                            Edit
                                        </button>
                                        {!role.is_system && (
                                            <button
                                                type="button"
                                                className="admin-table-icon-btn"
                                                title="Remove"
                                                onClick={() => remove(role)}
                                            >
                                                <Trash2 size={14} />
                                            </button>
                                        )}
                                    </div>
                                </header>

                                {role.description && (
                                    <p className="role-desc">
                                        {role.description}
                                    </p>
                                )}

                                {isOwner && (
                                    <p className="role-note">
                                        The owner covers everything. Taking a
                                        part away could lock the shop out of
                                        this screen, so it cannot be changed.
                                    </p>
                                )}
                                {isCustomer && (
                                    <p className="role-note">
                                        Customers shop here and see no part of
                                        the admin.
                                    </p>
                                )}

                                {!isCustomer && (
                                    <div className="role-abilities">
                                        {abilities.map((a) => (
                                            <Checkbox
                                                key={a.key}
                                                id={`r${role.id}-${a.key}`}
                                                name={`r${role.id}-${a.key}`}
                                                label={a.label}
                                                checked={
                                                    isOwner ||
                                                    role.abilities.includes(
                                                        a.key,
                                                    )
                                                }
                                                disabled={
                                                    locked ||
                                                    saving ===
                                                        `${role.id}:${a.key}`
                                                }
                                                onChange={(e) =>
                                                    toggleAbility(
                                                        role,
                                                        a.key,
                                                        e.target.checked,
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                )}
                            </article>
                        );
                    })}
                </div>
            )}

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.label}` : 'New role'}
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
                            {formik.isSubmitting ? 'Saving…' : 'Save role'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit} noValidate>
                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Name"
                            name="label"
                            formik={formik}
                            required
                            placeholder="Shift lead"
                        />
                        <FormInput
                            label="Key"
                            name="key"
                            formik={formik}
                            required
                            disabled={Boolean(editing?.is_system)}
                            placeholder="shift_lead"
                        />
                    </div>
                    {editing?.is_system ? (
                        <span className="admin-field-hint">
                            Accounts store this key, so it cannot move without
                            orphaning everyone holding it.
                        </span>
                    ) : (
                        <span className="admin-field-hint">
                            Stored on each account. Choose it once — changing it
                            later orphans anyone holding the old one.
                        </span>
                    )}

                    <FormInput
                        label="What the job is"
                        name="description"
                        type="textarea"
                        rows={3}
                        formik={formik}
                        placeholder="Runs the floor on evenings. Orders and stock, no money."
                    />

                    {!editing && (
                        <div className="role-modal-abilities">
                            <span className="admin-form-field-label">
                                What it covers
                            </span>
                            {abilities.map((a) => (
                                <Checkbox
                                    key={a.key}
                                    name={`new-${a.key}`}
                                    label={a.label}
                                    checked={formik.values.abilities.includes(
                                        a.key,
                                    )}
                                    onChange={(e) =>
                                        formik.setFieldValue(
                                            'abilities',
                                            e.target.checked
                                                ? [
                                                      ...formik.values
                                                          .abilities,
                                                      a.key,
                                                  ]
                                                : formik.values.abilities.filter(
                                                      (x) => x !== a.key,
                                                  ),
                                        )
                                    }
                                />
                            ))}
                        </div>
                    )}
                </form>
            </Modal>
        </AdminLayout>
    );
}
