import React, { useCallback, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { Wallet, Plus, Edit2, Trash2 } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminExpenseSchema } from '@/validations';
import { formatBdt, formatDate } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Expenses.css';

const today = () => new Date().toISOString().slice(0, 10);

const emptyExpense = () => ({
    category: 'rent',
    amount: '',
    description: '',
    incurred_on: today(),
    reference: '',
    note: '',
    supplier_id: '',
});

/**
 * What the shop spends that is not stock.
 *
 * Buying stock is deliberately absent: those units are inventory until they
 * sell, and they reach the accounts as cost of goods sold on the order that
 * sells them. Entering a delivery here as well would count the same money
 * twice, and make every month with a big delivery look like a loss it was not.
 */
export default function AdminExpenses({
    expenses = {},
    filters = {},
    categories = [],
    suppliers = [],
    total = 0,
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const isFiltered = Boolean(
        filters.from ||
        filters.to ||
        filters.search ||
        (filters.category && filters.category !== 'all'),
    );

    const categoryOptions = useMemo(
        () => categories.map((c) => ({ value: c.value, label: c.label })),
        [categories],
    );

    const supplierOptions = useMemo(
        () => [
            { value: '', label: 'Not linked to a supplier' },
            ...suppliers.map((s) => ({ value: String(s.id), label: s.name })),
        ],
        [suppliers],
    );

    const formik = useFormik({
        initialValues: emptyExpense(),
        validationSchema: adminExpenseSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            // An empty select is "no supplier", not supplier zero.
            const payload = {
                ...values,
                supplier_id: values.supplier_id || null,
            };

            try {
                if (editing) {
                    await adminService.updateExpense(editing.id, payload);
                    toast.success('Expense updated.');
                } else {
                    await adminService.createExpense(payload);
                    toast.success('Expense recorded.');
                }

                setModalOpen(false);
                setEditing(null);
                resetForm({ values: emptyExpense() });
                router.reload({ only: ['expenses', 'total'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that expense.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: emptyExpense() });
        setModalOpen(true);
    };

    const openEdit = (expense) => {
        setEditing(expense);
        formik.resetForm({
            values: {
                category: expense.category || 'rent',
                amount: expense.amount ?? '',
                description: expense.description || '',
                incurred_on: (expense.incurred_on || '').slice(0, 10),
                reference: expense.reference || '',
                note: expense.note || '',
                supplier_id: expense.supplier_id
                    ? String(expense.supplier_id)
                    : '',
            },
        });
        setModalOpen(true);
    };

    const remove = async (expense) => {
        if (!confirm(`Remove "${expense.description}"?`)) return;

        try {
            await adminService.deleteExpense(expense.id);
            toast.success('Expense removed.');
            router.reload({ only: ['expenses', 'total'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that expense.');
        }
    };

    // Stable identity: an inline arrow re-fires the search effect forever.
    const applyFilter = useCallback((patch) => {
        router.get(
            ROUTES.ADMIN_EXPENSES,
            { ...patch },
            {
                preserveState: true,
                replace: true,
                only: ['expenses', 'filters', 'total'],
            },
        );
    }, []);

    const handleSearch = useCallback(
        (value) => applyFilter({ search: value || undefined }),
        [applyFilter],
    );

    const columns = [
        {
            key: 'incurred_on',
            header: 'Date',
            render: (e) => formatDate(e.incurred_on),
        },
        {
            key: 'description',
            header: 'What it was',
            render: (e) => (
                <div>
                    <div className="admin-stock-product-name">
                        {e.description}
                    </div>
                    {(e.reference || e.supplier?.name) && (
                        <div className="admin-field-hint">
                            {[e.supplier?.name, e.reference]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'category',
            header: 'Category',
            render: (e) =>
                categories.find((c) => c.value === e.category)?.label ||
                e.category,
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (e) => formatBdt(e.amount),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (e) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Edit expense"
                        onClick={() => openEdit(e)}
                    >
                        <Edit2 size={14} />
                    </button>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Remove expense"
                        onClick={() => remove(e)}
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Expenses"
            subtitle="Rent, wages, delivery and everything else that is not stock"
        >
            <Head title="Expenses" />

            {/*
             * The same summary strip the stock screen uses, so the two money
             * pages read alike. The filters live in it rather than floating
             * above the table: they change what the total counts, so they
             * belong beside it.
             */}
            <div className="admin-stock-summary">
                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {formatBdt(total)}
                    </span>
                    <span className="admin-stock-stat-label">
                        {isFiltered
                            ? 'Matching this filter'
                            : 'Recorded in total'}
                    </span>
                </div>

                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {(expenses.total ?? 0).toLocaleString()}
                    </span>
                    <span className="admin-stock-stat-label">
                        {expenses.total === 1 ? 'Entry' : 'Entries'}
                    </span>
                </div>

                <div className="admin-stock-stat admin-stock-branch-filter">
                    <label
                        className="admin-stock-stat-label"
                        htmlFor="category-filter"
                    >
                        Category
                    </label>
                    <select
                        id="category-filter"
                        value={filters.category || 'all'}
                        onChange={(e) =>
                            applyFilter({
                                category:
                                    e.target.value === 'all'
                                        ? undefined
                                        : e.target.value,
                            })
                        }
                    >
                        <option value="all">All categories</option>
                        {categoryOptions.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="admin-stock-stat expense-range-filter">
                    <span className="admin-stock-stat-label">Period</span>
                    <div className="expense-range-inputs">
                        <input
                            type="date"
                            aria-label="From"
                            value={filters.from || ''}
                            max={filters.to || undefined}
                            onChange={(e) =>
                                applyFilter({
                                    from: e.target.value || undefined,
                                })
                            }
                        />
                        <span>–</span>
                        <input
                            type="date"
                            aria-label="To"
                            value={filters.to || ''}
                            min={filters.from || undefined}
                            onChange={(e) =>
                                applyFilter({ to: e.target.value || undefined })
                            }
                        />
                    </div>
                </div>
            </div>

            {/*
             * The thing most likely to be got wrong, said once and plainly
             * rather than crammed into a stat card: buying stock is not
             * spending. It turns cash into inventory, and only becomes a cost
             * when the units sell.
             */}
            <p className="expense-scope-note">
                Stock purchases do not belong here — they are recorded as
                deliveries and reach the accounts as cost of goods sold when the
                units sell.
            </p>

            <DataTable
                columns={columns}
                data={expenses}
                title="Expenses"
                subtitle="Most recent first"
                searchable
                searchValue={filters.search || ''}
                onSearch={handleSearch}
                searchPlaceholder="Search description, reference or note..."
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        Record expense
                    </Button>
                }
                emptyTitle="Nothing recorded yet"
                emptyDescription="Add the shop's running costs — rent, wages, the courier's bill — and they will appear in the profit and loss statement."
                emptyIcon={Wallet}
                emptyActionText="Record expense"
                onEmptyAction={openCreate}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? 'Edit expense' : 'Record expense'}
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
                                  : 'Record expense'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit}>
                    <FormInput
                        label="What was it for"
                        name="description"
                        formik={formik}
                        required
                        placeholder="Shop rent, August"
                    />

                    <div className="admin-grid-equal-2col">
                        <FormSelect
                            label="Category"
                            name="category"
                            formik={formik}
                            required
                            options={categoryOptions}
                        />
                        <FormInput
                            label="Amount (৳ BDT)"
                            name="amount"
                            type="number"
                            formik={formik}
                            required
                            placeholder="25000"
                        />
                    </div>

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Date incurred"
                            name="incurred_on"
                            type="date"
                            formik={formik}
                            required
                        />
                        <FormInput
                            label="Reference"
                            name="reference"
                            formik={formik}
                            placeholder="Invoice or receipt number"
                        />
                    </div>

                    <FormSelect
                        label="Supplier"
                        name="supplier_id"
                        formik={formik}
                        options={supplierOptions}
                    />

                    <FormInput
                        label="Note"
                        name="note"
                        formik={formik}
                        placeholder="Anything worth remembering about this cost"
                    />
                </form>
            </Modal>
        </AdminLayout>
    );
}
