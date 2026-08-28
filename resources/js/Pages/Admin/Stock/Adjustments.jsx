import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { SlidersHorizontal, ArrowRight, ClipboardList } from 'lucide-react';
import { Link } from '@inertiajs/react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Count.css';

/**
 * Every correction made to stock, and what it cost.
 *
 * Adjustments were only ever visible one product at a time, so there was
 * nowhere to see that a branch had written off nine graphics cards this month,
 * or that the same person recorded all of them.
 */
export default function StockAdjustments({
    movements = { data: [] },
    filters = {},
    reasons = {},
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
        {
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
        { key: 'reason', header: 'Reason', render: (m) => m.reason },
        {
            key: 'who',
            header: 'Where & who',
            render: (m) => (
                <div>
                    <div>{m.store ?? '—'}</div>
                    <div className="admin-field-hint">{m.by ?? 'System'}</div>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Stock adjustments"
            subtitle={
                branch
                    ? `Every correction made at ${branch}`
                    : 'Every correction made to stock, and what it cost'
            }
        >
            <Head title="Stock adjustments" />

            {/*
             * This screen is the record, not the place corrections are made —
             * an adjustment needs a product, and the product list is where you
             * have one in front of you. Saying so, with the way there, beats
             * leaving somebody to hunt for a button that is on another page.
             */}
            <div className="adj-intro">
                <p>
                    Corrections are made from the product list: find the item,
                    press <strong>Adjust</strong>, and say how many and why.
                    Counting a whole branch at once is a{' '}
                    <strong>stock take</strong>.
                </p>
                <div className="admin-input-row-flex">
                    <Link href={ROUTES.ADMIN_STOCK}>
                        <Button variant="secondary" icon={ArrowRight}>
                            Adjust a product
                        </Button>
                    </Link>
                    <Link href={ROUTES.ADMIN_STOCK_COUNT}>
                        <Button variant="secondary" icon={ClipboardList}>
                            Start a stock take
                        </Button>
                    </Link>
                </div>
            </div>

            {/* The three numbers the screen exists to answer. */}
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
                    <span className="admin-attention-hint">In this period</span>
                </div>
                <div className="admin-attention-card">
                    <span className="admin-attention-count">
                        {summary.units_found ?? 0}
                    </span>
                    <span className="admin-attention-label">Units found</span>
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
                    <span className="admin-attention-label">Value change</span>
                    <span className="admin-attention-hint">
                        At what those units cost
                    </span>
                </div>
            </div>

            <DataTable
                columns={columns}
                data={movements.data ?? []}
                title="Adjustments"
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
                        <select
                            className="count-branch-select"
                            value={filters.reason || ''}
                            onChange={(e) =>
                                go({ reason: e.target.value || undefined })
                            }
                        >
                            <option value="">Any reason</option>
                            {Object.entries(reasons).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        {!branch && stores.length > 1 && (
                            <select
                                className="count-branch-select"
                                value={filters.store || ''}
                                onChange={(e) =>
                                    go({ store: e.target.value || undefined })
                                }
                            >
                                <option value="">All branches</option>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                }
                emptyTitle="No adjustments in this period"
                emptyDescription="Nothing has been corrected in this period. Adjustments made from the product list, and stock takes, appear here."
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
