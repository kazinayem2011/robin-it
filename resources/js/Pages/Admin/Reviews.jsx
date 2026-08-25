import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { Button, DataTable, toast } from '../../Components';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import { Star, Eye, EyeOff, Trash2, MessageSquare } from 'lucide-react';

const TABS = [
    { key: 'all', label: 'All' },
    { key: 'published', label: 'Published' },
    { key: 'hidden', label: 'Hidden' },
];

export default function AdminReviews({
    reviews = { data: [] },
    filters = {},
    counts = {},
}) {
    const [busyId, setBusyId] = useState(null);

    const setStatus = async (review, isApproved) => {
        setBusyId(review.id);
        try {
            await axiosInstance.patch(
                API_ENDPOINTS.ADMIN.REVIEW_STATUS(review.id),
                { is_approved: isApproved },
            );
            toast.success(
                isApproved
                    ? 'Review is now visible on the storefront.'
                    : 'Review hidden from the storefront.',
                'Review Updated',
            );
            router.reload({ only: ['reviews', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not update that review.');
        } finally {
            setBusyId(null);
        }
    };

    const remove = async (review) => {
        if (
            !window.confirm(
                `Permanently delete this review by ${review.author_name}? This cannot be undone.`,
            )
        ) {
            return;
        }

        setBusyId(review.id);
        try {
            await axiosInstance.delete(
                API_ENDPOINTS.ADMIN.REVIEW_ITEM(review.id),
            );
            toast.success('Review deleted.');
            router.reload({ only: ['reviews', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not delete that review.');
        } finally {
            setBusyId(null);
        }
    };

    const columns = [
        {
            key: 'product',
            header: 'Product',
            render: (r) => (
                <div>
                    <span className="font-semibold text-sm">
                        {r.product?.name || 'Removed product'}
                    </span>
                </div>
            ),
        },
        {
            key: 'rating',
            header: 'Rating',
            render: (r) => (
                <span className="admin-review-rating">
                    <Star size={13} fill="#F59E0B" color="#F59E0B" />
                    {r.rating}
                </span>
            ),
        },
        {
            key: 'review',
            header: 'Review',
            render: (r) => (
                <div className="admin-review-cell">
                    {r.title && <strong>{r.title}</strong>}
                    <p>{r.comment}</p>
                    <small>
                        {r.author_name}
                        {r.is_verified_buyer ? ' · Verified buyer' : ''}
                    </small>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (r) => (
                <span
                    className={`badge ${r.is_approved ? 'badge-new' : 'badge-hot'}`}
                >
                    {r.is_approved ? 'Published' : 'Hidden'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            render: (r) => (
                <div className="admin-review-actions">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busyId === r.id}
                        icon={r.is_approved ? EyeOff : Eye}
                        onClick={() => setStatus(r, !r.is_approved)}
                    >
                        {r.is_approved ? 'Hide' : 'Publish'}
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        disabled={busyId === r.id}
                        icon={Trash2}
                        onClick={() => remove(r)}
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Customer Reviews"
            subtitle="Moderate published product reviews and take down anything inappropriate"
        >
            <Head title="Review Moderation" />

            <div className="admin-page-container">
                <div className="admin-settings-tabs-bar">
                    {TABS.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            className={`admin-tab-btn ${
                                (filters.status || 'all') === tab.key
                                    ? 'active'
                                    : ''
                            }`}
                            onClick={() =>
                                router.get(
                                    API_ENDPOINTS.ADMIN.REVIEWS,
                                    { status: tab.key },
                                    { preserveState: true },
                                )
                            }
                        >
                            {tab.label}
                            {counts[tab.key] !== undefined && (
                                <span className="admin-tab-count">
                                    {' '}
                                    ({counts[tab.key]})
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                <DataTable
                    title="Product Reviews"
                    subtitle={`${counts.published ?? 0} published · ${counts.hidden ?? 0} hidden`}
                    columns={columns}
                    data={reviews}
                    emptyIcon={MessageSquare}
                    emptyTitle="No reviews yet"
                    emptyDescription="Verified buyers can leave a review from any product page."
                />
            </div>
        </AdminLayout>
    );
}
