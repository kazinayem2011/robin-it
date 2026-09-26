import Select from '@/Components/Select';
import { StockTabs } from './StockTabs';
import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { SlidersHorizontal } from 'lucide-react';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Count.css';

/**
 * Every change to stock — deliveries, sales, returns, transfers and
 * corrections — filtered by what happened. It was corrections alone.
 *
 * And every correction made to stock, and what it cost.
 *
 * Adjustments were only ever visible one product at a time, so there was
 * nowhere to see that a branch had written off nine graphics cards this month,
 * or that the same person recorded all of them.
 */
export default function StockAdjustments({
    movements = { data: [] },
    filters = {},
    reasons = {},
    kinds = [],
    stores = [],
    branch = null,
    summary = {},
}) {
    const go = (params) =>
        router.get(
            ROUTES.ADMIN_STOCK_ADJUSTMENTS,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const kind = filters.kind || 'all';
    // What units were worth only means something for a correction.
    const showValue = kind === 'corrections';

    const columns = [
        {
            key: 'when',
            header: 'When',
            render: (m) => <span className="admin-field-hint">{m.when}</span>,
        },
        {
            key: 'name',
            header: 'Product',
            render: (m) => (
                <div>
                    <div className="admin-stock-product-name">{m.name}</div>
                    {m.note && <div className="admin-field-hint">{m.note}</div>}
                </div>
            ),
        },
        {
            key: 'quantity',
            header: 'Change',
            align: 'right',
            render: (m) => (
                <span
                    className={`count-diff ${m.quantity > 0 ? 'is-up' : 'is-down'}`}
                >
                    {m.quantity > 0 ? '+' : ''}
                    {m.quantity}
                </span>
            ),
        },
        showValue && {
            key: 'value',
            header: 'Value',
            align: 'right',
            render: (m) =>
                m.value === null ? (
                    <span className="admin-field-hint">cost unknown</span>
                ) : (
                    <span
                        className={
                            m.value < 0
                                ? 'count-value-loss'
                                : 'count-value-gain'
                        }
                    >
                        <strong>{formatBdt(m.value)}</strong>
                    </span>
                ),
        },
        { key: 'what', header: 'What happened', render: (m) => m.what },
        {
            key: 'who',
            header: 'Branch & who',
            render: (m) => (
                <div>
                    <div>{m.store ?? '—'}</div>
                    <div className="admin-field-hint">{m.by ?? 'System'}</div>
                </div>
            ),
        },
    ].filter(Boolean);

    return (
        <AdminLayout
            title="Stock"
            subtitle={
                branch
                    ? `Everything that changed stock at ${branch}`
                    : 'Everything that changed stock: deliveries, sales, returns, transfers and corrections'
            }
        >
            <Head title="Stock adjustments" />
            <StockTabs current={ROUTES.ADMIN_STOCK_ADJUSTMENTS} />

            <p className="admin-field-hint adj-intro-line">
                Everything that changed stock, newest first. To fix a wrong
                count, find the product on the <strong>Stock</strong> tab and
                press <strong>Correct</strong>.
            </p>

            {/* The three numbers the screen exists to answer. */}
            {showValue && (
                <div
                    className="admin-attention-grid"
                    style={{ marginBottom: '18px' }}
                >
                    <div className="admin-attention-card">
                        <span className="admin-attention-count">
                            {summary.units_lost ?? 0}
                        </span>
                        <span className="admin-attention-label">
                            Units written off
                        </span>
                        <span className="admin-attention-hint">
                            In this period
                        </span>
                    </div>
                    <div className="admin-attention-card">
                        <span className="admin-attention-count">
                            {summary.units_found ?? 0}
                        </span>
                        <span className="admin-attention-label">
                            Units found
                        </span>
                        <span className="admin-attention-hint">
                            Counted higher than the books
                        </span>
                    </div>
                    <div
                        className={`admin-attention-card ${(summary.value_change ?? 0) < 0 ? 'tone-warn' : ''}`}
                    >
                        <span className="admin-attention-count">
                            {formatBdt(summary.value_change ?? 0)}
                        </span>
                        <span className="admin-attention-label">
                            Value change
                        </span>
                        <span className="admin-attention-hint">
                            At what those units cost
                        </span>
                    </div>
                </div>
            )}

            <DataTable
                columns={columns}
                data={movements.data ?? []}
                title="History"
                subtitle={`${filters.from} to ${filters.to}`}
                headerActions={
                    <div className="admin-input-row-flex">
                        <input
                            type="date"
                            className="count-note"
                            value={filters.from || ''}
                            onChange={(e) => go({ from: e.target.value })}
                        />
                        <input
                            type="date"
                            className="count-note"
                            value={filters.to || ''}
                            onChange={(e) => go({ to: e.target.value })}
                        />
                        <Select
                            className="count-branch-select"
                            aria-label="Show"
                            value={kind}
                            onChange={(e) =>
                                go({
                                    kind: e.target.value,
                                    reason: undefined,
                                })
                            }
                            options={kinds}
                        />
                        {['all', 'corrections'].includes(kind) && (
                            <Select
                                className="count-branch-select"
                                value={filters.reason || ''}
                                onChange={(e) =>
                                    go({ reason: e.target.value || undefined })
                                }
                                options={[
                                    { value: '', label: 'Any reason' },
                                    ...Object.entries(reasons).map(
                                        ([value, label]) => ({ value, label }),
                                    ),
                                ]}
                            />
                        )}
                        {!branch && stores.length > 1 && (
                            <Select
                                className="count-branch-select"
                                value={filters.store || ''}
                                onChange={(e) =>
                                    go({ store: e.target.value || undefined })
                                }
                                options={[
                                    { value: '', label: 'All branches' },
                                    ...stores.map((s) => ({
                                        value: s.id,
                                        label: s.name,
                                    })),
                                ]}
                            />
                        )}
                    </div>
                }
                emptyTitle="Nothing in this period"
                emptyDescription="Nothing of this kind changed stock between these dates. Try another date or 'Everything'."
                emptyIcon={SlidersHorizontal}
                pagination={false}
            />

            {movements.last_page > 1 && (
                <Pagination
                    links={movements.links}
                    currentPage={movements.current_page}
                    totalPages={movements.last_page}
                    from={movements.from}
                    to={movements.to}
                    total={movements.total}
                />
            )}
        </AdminLayout>
    );
}
