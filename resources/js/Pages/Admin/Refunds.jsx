import React, { useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Undo2, Trash2 } from 'lucide-react';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt, formatDate } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Refunds.css';

/**
 * Money given back.
 *
 * The total counts what actually left the business: a cash-on-delivery parcel
 * that came back before the rider collected anything is recorded so the order
 * shows nothing owed, but no money moved and it would be wrong to call it a
 * payout.
 */
export default function AdminRefunds({
    refunds = {},
    filters = {},
    reasons = [],
    total = 0,
}) {
    const applyFilter = useCallback((patch) => {
        router.get(
            ROUTES.ADMIN_REFUNDS,
            { ...patch },
            {
                preserveState: true,
                replace: true,
                only: ['refunds', 'filters', 'total'],
            },
        );
    }, []);

    const remove = async (refund) => {
        if (
            !confirm(
                `Remove the ${formatBdt(refund.amount)} refund on #${refund.order?.order_number}? Use this to undo a mistake, not to reverse a refund that actually happened.`,
            )
        ) {
            return;
        }

        try {
            await adminService.deleteRefund(refund.id);
            toast.success('Refund removed.');
            router.reload({ only: ['refunds', 'total'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that refund.');
        }
    };

    const columns = [
        {
            key: 'refunded_on',
            header: 'Date',
            render: (r) => formatDate(r.refunded_on),
        },
        {
            key: 'order',
            header: 'Order',
            render: (r) => (
                <div>
                    <div className="admin-stock-product-name">
                        #{r.order?.order_number ?? '—'}
                    </div>
                    {r.reference && (
                        <div className="admin-field-hint">{r.reference}</div>
                    )}
                </div>
            ),
        },
        {
            key: 'reason',
            header: 'Why',
            render: (r) => (
                <div>
                    <div>{r.reason_label}</div>
                    <div className="admin-field-hint">{r.method_label}</div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (r) =>
                r.method === 'cod_not_collected' ? (
                    <span className="refund-not-paid">
                        {formatBdt(r.amount)}
                        <span>never collected</span>
                    </span>
                ) : (
                    formatBdt(r.amount)
                ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (r) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Remove this refund"
                        onClick={() => remove(r)}
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="Refunds" subtitle="Money given back to customers">
            <Head title="Refunds" />

            <div className="admin-stock-summary">
                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {formatBdt(total)}
                    </span>
                    <span className="admin-stock-stat-label">
                        Paid back — excludes cash never collected
                    </span>
                </div>

                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {(refunds.total ?? 0).toLocaleString()}
                    </span>
                    <span className="admin-stock-stat-label">
                        {refunds.total === 1 ? 'Refund' : 'Refunds'}
                    </span>
                </div>

                <div className="admin-stock-stat admin-stock-branch-filter">
                    <label
                        className="admin-stock-stat-label"
                        htmlFor="reason-filter"
                    >
                        Reason
                    </label>
                    <select
                        id="reason-filter"
                        value={filters.reason || 'all'}
                        onChange={(e) =>
                            applyFilter({
                                reason:
                                    e.target.value === 'all'
                                        ? undefined
                                        : e.target.value,
                            })
                        }
                    >
                        <option value="all">All reasons</option>
                        {reasons.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="admin-stock-stat refund-range-filter">
                    <span className="admin-stock-stat-label">Period</span>
                    <div className="refund-range-inputs">
                        <FormInput
                            name="from"
                            type="date"
                            value={filters.from || ''}
                            onChange={(e) =>
                                applyFilter({
                                    from: e.target.value || undefined,
                                })
                            }
                        />
                        <FormInput
                            name="to"
                            type="date"
                            value={filters.to || ''}
                            onChange={(e) =>
                                applyFilter({ to: e.target.value || undefined })
                            }
                        />
                    </div>
                </div>
            </div>

            <DataTable
                columns={columns}
                data={refunds}
                title="Refunds"
                subtitle="Most recent first"
                emptyTitle="Nothing refunded yet"
                emptyDescription="Refunds are recorded from an order, and appear here with what they cost the shop."
                emptyIcon={Undo2}
            />
        </AdminLayout>
    );
}
