import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import {
    Button,
    DataTable,
    FormInput,
    FormSelect,
    Checkbox,
    Modal,
    toast,
} from '../../Components';
import CouponScopePicker from './Components/CouponScopePicker';
import { adminService } from '../../services';
import { adminCouponSchema } from '../../validations';
import { formatBdt } from '../../utils/formatters';
import { Tag, Plus, Edit2, Trash2 } from 'lucide-react';

export default function AdminCoupons({
    coupons = [],
    products = [],
    categories = [],
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingCoupon, setEditingCoupon] = useState(null);

    const formik = useFormik({
        initialValues: {
            code: '',
            description: '',
            discount_type: 'percent',
            discount_value: 10,
            min_spend: 1000,
            max_discount: 2000,
            usage_limit: 500,
            per_user_limit: 1,
            is_active: true,
            scope: 'all',
            product_ids: [],
            category_ids: [],
        },
        validationSchema: adminCouponSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editingCoupon) {
                    await adminService.updateCoupon(editingCoupon.id, values);
                    toast.success('Coupon updated successfully!');
                } else {
                    await adminService.createCoupon(values);
                    toast.success('Coupon created successfully!');
                }
                setModalOpen(false);
                setEditingCoupon(null);
                resetForm();
                router.reload({ only: ['coupons'] });
            } catch (err) {
                toast.error(err?.message || 'Failed to save coupon.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleOpenCreate = () => {
        setEditingCoupon(null);
        formik.resetForm({
            values: {
                code: '',
                description: '',
                discount_type: 'percent',
                discount_value: 10,
                min_spend: 1000,
                max_discount: 2000,
                usage_limit: 500,
                per_user_limit: 1,
                is_active: true,
                scope: 'all',
                product_ids: [],
                category_ids: [],
            },
        });
        setModalOpen(true);
    };

    const handleOpenEdit = (coupon) => {
        setEditingCoupon(coupon);
        formik.resetForm({
            values: {
                code: coupon.code || '',
                description: coupon.description || '',
                discount_type: coupon.discount_type || 'percent',
                discount_value: coupon.discount_value || 10,
                min_spend: coupon.min_spend || '',
                max_discount: coupon.max_discount || '',
                usage_limit: coupon.usage_limit || 500,
                per_user_limit: coupon.per_user_limit ?? '',
                is_active: Boolean(coupon.is_active),
                scope: coupon.scope || 'all',
                product_ids: (coupon.products || []).map((p) => p.id),
                category_ids: (coupon.categories || []).map((c) => c.id),
            },
        });
        setModalOpen(true);
    };

    const handleDelete = async (id) => {
        if (!confirm('Are you sure you want to delete this coupon?')) return;
        try {
            await adminService.deleteCoupon(id);
            toast.success('Coupon removed.');
            router.reload({ only: ['coupons'] });
        } catch (err) {
            toast.error('Failed to delete coupon.');
        }
    };

    // Columns Definition for Reusable DataTable (SSOT)
    const columns = [
        {
            key: 'code',
            header: 'Coupon Code',
            render: (row) => (
                <div>
                    <div className="coupon-code-pill">
                        <Tag size={13} />
                        <strong>{row.code}</strong>
                    </div>
                    {row.description && (
                        <small className="coupon-desc-sub">
                            {row.description}
                        </small>
                    )}
                </div>
            ),
        },
        {
            key: 'discount',
            header: 'Discount',
            render: (row) => (
                <strong className="text-primary">
                    {row.discount_type === 'percent'
                        ? `${row.discount_value}% OFF`
                        : formatBdt(row.discount_value)}
                </strong>
            ),
        },
        {
            key: 'min_spend',
            header: 'Min. Spend',
            render: (row) =>
                row.min_spend ? formatBdt(row.min_spend) : 'None',
        },
        {
            key: 'max_discount',
            header: 'Max Discount',
            render: (row) =>
                row.max_discount ? formatBdt(row.max_discount) : 'Unlimited',
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => (
                <span
                    className={`status-pill ${row.is_active ? 'active' : 'inactive'}`}
                >
                    {row.is_active ? 'Active' : 'Expired'}
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
            title="Promo Coupons &amp; Discounts"
            subtitle="Create and manage checkout discount codes and promotional vouchers"
        >
            <Head title="Admin Coupons — Robin IT" />

            <div className="admin-page-container">
                {/* Reusable DataTable Component with Header Actions */}
                <DataTable
                    title="Active Promo Codes"
                    subtitle="Discounts are validated live at checkout against order subtotals and minimum spend limits."
                    columns={columns}
                    data={coupons}
                    emptyTitle="No Promo Coupons Found"
                    emptyDescription="You haven't created any promotional discount codes yet."
                    emptyIcon={Tag}
                    emptyActionText="Create First Coupon"
                    onEmptyAction={handleOpenCreate}
                    headerActions={
                        <Button
                            variant="primary"
                            icon={Plus}
                            onClick={handleOpenCreate}
                        >
                            Create Coupon
                        </Button>
                    }
                />

                {/* Unified Create / Edit Modal (Single SSOT Form) */}
                <Modal
                    isOpen={modalOpen}
                    onClose={() => {
                        setModalOpen(false);
                        setEditingCoupon(null);
                    }}
                    title={
                        editingCoupon
                            ? `Edit Coupon: ${editingCoupon.code}`
                            : 'Create Promo Coupon'
                    }
                    maxWidth="540px"
                >
                    <form onSubmit={formik.handleSubmit}>
                        <div className="admin-form-stack">
                            <FormInput
                                label="Coupon Code (e.g. WELCOME10)"
                                name="code"
                                required
                                formik={formik}
                                onChange={(e) =>
                                    formik.setFieldValue(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="WELCOME10"
                            />

                            <FormInput
                                label="Offer Description"
                                name="description"
                                formik={formik}
                                placeholder="10% off on all genuine hardware orders"
                            />

                            <div className="admin-form-grid-2">
                                <FormSelect
                                    label="Discount Type"
                                    name="discount_type"
                                    formik={formik}
                                    options={[
                                        {
                                            value: 'percent',
                                            label: 'Percentage (% Off)',
                                        },
                                        {
                                            value: 'fixed',
                                            label: 'Fixed Amount (৳ BDT Off)',
                                        },
                                    ]}
                                />

                                <FormInput
                                    label="Discount Value"
                                    name="discount_value"
                                    type="number"
                                    required
                                    formik={formik}
                                />
                            </div>

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Minimum Spend (৳)"
                                    name="min_spend"
                                    type="number"
                                    formik={formik}
                                />
                                <FormInput
                                    label="Max Discount Cap (৳)"
                                    name="max_discount"
                                    type="number"
                                    formik={formik}
                                />
                            </div>

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Total Uses (all customers)"
                                    name="usage_limit"
                                    type="number"
                                    formik={formik}
                                    placeholder="Leave blank for unlimited"
                                />
                                <FormInput
                                    label="Uses Per Customer"
                                    name="per_user_limit"
                                    type="number"
                                    formik={formik}
                                    placeholder="Leave blank for unlimited"
                                />
                            </div>

                            <CouponScopePicker
                                formik={formik}
                                products={products}
                                categories={categories}
                            />

                            <div>
                                <Checkbox
                                    name="is_active"
                                    label="Activate Coupon immediately at Checkout"
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
                                        setEditingCoupon(null);
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={formik.isSubmitting}
                                >
                                    {editingCoupon
                                        ? 'Update Coupon'
                                        : 'Save Coupon'}
                                </Button>
                            </div>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
