import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { Tags, Plus, Edit2, Trash2, AlertTriangle } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminExpenseCategorySchema } from '@/validations';
import { formatBdt } from '@/utils/formatters';
import './Expenses.css';

const empty = { name: '', note: '' };

/**
 * What the shop spends money on.
 *
 * These used to be a constant in the Expense model, so changing the list meant
 * a deploy. Every business spends on something the next one does not.
 */
export default function AdminExpenseCategories({
    categories = [],
    inventoryWords = [],
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const formik = useFormik({
        initialValues: empty,
        validationSchema: adminExpenseCategorySchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editing) {
                    await adminService.updateExpenseCategory(
                        editing.id,
                        values,
                    );
                    toast.success(`"${values.name}" updated.`);
                } else {
                    await adminService.createExpenseCategory(values);
                    toast.success(`"${values.name}" added.`);
                }

                setModalOpen(false);
                setEditing(null);
                resetForm({ values: empty });
                router.reload({ only: ['categories'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that category.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    /*
     * Buying stock is not spending — it turns cash into inventory, and only
     * becomes a cost when the units sell. A category named for it would count
     * the same money twice. Warned about while typing, never refused: the shop
     * may have a reason, and being told why is more use than being blocked.
     */
    const looksLikeStock = inventoryWords.some((word) =>
        (formik.values.name || '').toLowerCase().includes(word),
    );

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: empty });
        setModalOpen(true);
    };

    const openEdit = (category) => {
        setEditing(category);
        formik.resetForm({
            values: { name: category.name || '', note: category.note || '' },
        });
        setModalOpen(true);
    };

    const remove = async (category) => {
        const warning = category.expenses_count
            ? `"${category.name}" has ${category.expenses_count} expense(s) filed under it. It will be hidden rather than deleted so the spending survives. Continue?`
            : `Remove "${category.name}"?`;

        if (!confirm(warning)) return;

        try {
            const res = await adminService.deleteExpenseCategory(category.id);
            toast.success(
                res?.name ? `"${res.name}" hidden.` : 'Category removed.',
            );
            router.reload({ only: ['categories'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that category.');
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Category',
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
                    {c.note && <div className="admin-field-hint">{c.note}</div>}
                </div>
            ),
        },
        {
            key: 'expenses',
            header: 'Entries',
            render: (c) => c.expenses_count ?? 0,
        },
        {
            key: 'spend',
            header: 'Total spend',
            align: 'right',
            render: (c) => (c.total_spend > 0 ? formatBdt(c.total_spend) : '—'),
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
                        title="Rename category"
                        onClick={() => openEdit(c)}
                    >
                        <Edit2 size={14} />
                    </button>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Remove category"
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
            title="Expense Categories"
            subtitle="What the shop's running costs are filed under"
        >
            <Head title="Expense Categories" />

            <DataTable
                columns={columns}
                data={categories}
                title="Categories"
                subtitle="Offered when recording an expense"
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        Add category
                    </Button>
                }
                emptyTitle="No categories yet"
                emptyDescription="Add the headings the shop files its running costs under."
                emptyIcon={Tags}
                emptyActionText="Add category"
                onEmptyAction={openCreate}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add category'}
                maxWidth="560px"
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
                                  : 'Add category'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit} noValidate>
                    <FormInput
                        label="Name"
                        name="name"
                        formik={formik}
                        required
                        placeholder="Legal & accounting"
                    />

                    {looksLikeStock && (
                        <div className="expense-category-warning">
                            <AlertTriangle size={16} />
                            <div>
                                <strong>
                                    This sounds like stock, which is not an
                                    expense.
                                </strong>
                                <span>
                                    Units bought are inventory until they sell,
                                    and they already reach the accounts as cost
                                    of goods sold on the order that sells them.
                                    Recording deliveries here as well would
                                    count the same money twice. You can still
                                    save this if you mean something else.
                                </span>
                            </div>
                        </div>
                    )}

                    <FormInput
                        label="Note"
                        name="note"
                        formik={formik}
                        placeholder="Optional — what belongs under this heading"
                    />
                </form>
            </Modal>
        </AdminLayout>
    );
}
