import React, { useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { AlertTriangle, Wallet } from 'lucide-react';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Reports.css';

/*
 * Local date parts, not toISOString().
 *
 * toISOString() converts to UTC first, so east of Greenwich the 1st of the
 * month comes back as the 31st of the previous one — "This month" was asking
 * the server for a period that started a day early and pulled in the month
 * before it.
 */
const iso = (d) =>
    [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0'),
    ].join('-');

/**
 * The periods someone actually asks for, so the common case is one click
 * rather than two date pickers.
 */
const PRESETS = [
    {
        key: 'this-month',
        label: 'This month',
        range: () => {
            const n = new Date();
            return [iso(new Date(n.getFullYear(), n.getMonth(), 1)), iso(n)];
        },
    },
    {
        key: 'last-month',
        label: 'Last month',
        range: () => {
            const n = new Date();
            return [
                iso(new Date(n.getFullYear(), n.getMonth() - 1, 1)),
                iso(new Date(n.getFullYear(), n.getMonth(), 0)),
            ];
        },
    },
    {
        key: 'last-3',
        label: 'Last 3 months',
        range: () => {
            const n = new Date();
            return [
                iso(new Date(n.getFullYear(), n.getMonth() - 2, 1)),
                iso(n),
            ];
        },
    },
    {
        key: 'this-year',
        label: 'This year',
        range: () => {
            const n = new Date();
            return [iso(new Date(n.getFullYear(), 0, 1)), iso(n)];
        },
    },
];

/**
 * Profit and loss for a period.
 *
 * The two sides come from deliberately different places:
 *
 *   Cost of goods sold is priced from the order lines — what the units that
 *   *sold* cost the shop. Not what was bought in the period: a delivery still
 *   on the shelf has not cost anything yet, it has turned cash into stock.
 *
 *   Everything under Expenses is money that left and did not come back as
 *   something sellable.
 */
export default function AdminReports({ statement = {}, filters = {} }) {
    const income = statement.income || {};
    const expenses = statement.expenses || { total: 0, by_category: [] };
    const excluded = statement.excluded || { orders: 0, revenue: 0 };

    const net = statement.net_profit ?? 0;
    const counted = statement.orders_counted ?? 0;

    const setPeriod = (from, to) =>
        router.get(
            ROUTES.ADMIN_REPORTS_PROFIT,
            { from, to },
            { preserveState: true, replace: true },
        );

    const activePreset = useMemo(
        () =>
            PRESETS.find((p) => {
                const [from, to] = p.range();
                return from === filters.from && to === filters.to;
            })?.key,
        [filters.from, filters.to],
    );

    // Only the categories actually spent on; a statement listing nine zeroes
    // buries the two lines that matter.
    const spent = (expenses.by_category || []).filter((c) => c.amount > 0);

    return (
        <AdminLayout
            title="Profit & Loss"
            subtitle="What the shop earned and what it spent"
        >
            <Head title="Profit & Loss" />

            <div className="pl-periods">
                <div className="pl-preset-row">
                    {PRESETS.map((p) => (
                        <button
                            key={p.key}
                            type="button"
                            className={`pl-preset ${activePreset === p.key ? 'is-active' : ''}`}
                            onClick={() => setPeriod(...p.range())}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>

                <div className="pl-custom-range">
                    <label htmlFor="pl-from">From</label>
                    <input
                        id="pl-from"
                        type="date"
                        value={filters.from || ''}
                        max={filters.to || undefined}
                        onChange={(e) => setPeriod(e.target.value, filters.to)}
                    />
                    <label htmlFor="pl-to">To</label>
                    <input
                        id="pl-to"
                        type="date"
                        value={filters.to || ''}
                        min={filters.from || undefined}
                        onChange={(e) =>
                            setPeriod(filters.from, e.target.value)
                        }
                    />
                </div>
            </div>

            <div className="admin-stock-summary">
                <div
                    className={`admin-stock-stat pl-stat-headline ${net >= 0 ? 'is-profit' : 'is-loss'}`}
                >
                    <span className="admin-stock-stat-value">
                        {formatBdt(net)}
                    </span>
                    <span className="admin-stock-stat-label">
                        {net >= 0 ? 'Net profit' : 'Net loss'}
                        {statement.net_margin_percent !== null &&
                            statement.net_margin_percent !== undefined &&
                            ` · ${statement.net_margin_percent}% of income`}
                    </span>
                </div>

                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {formatBdt(income.total)}
                    </span>
                    <span className="admin-stock-stat-label">
                        Total income, net of VAT · {counted} costed order
                        {counted === 1 ? '' : 's'}
                    </span>
                </div>

                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {formatBdt(statement.gross_profit)}
                    </span>
                    <span className="admin-stock-stat-label">
                        Gross profit
                        {statement.gross_margin_percent !== null &&
                            statement.gross_margin_percent !== undefined &&
                            ` · ${statement.gross_margin_percent}% margin`}
                    </span>
                </div>

                <div className="admin-stock-stat">
                    <span className="admin-stock-stat-value">
                        {formatBdt(expenses.total)}
                    </span>
                    <span className="admin-stock-stat-label">
                        Running costs, excluding stock
                    </span>
                </div>
            </div>

            {excluded.orders > 0 && (
                <div className="pl-caveat">
                    <AlertTriangle size={16} />
                    <div>
                        <strong>
                            {excluded.orders} order
                            {excluded.orders === 1 ? '' : 's'} are not in these
                            figures.
                        </strong>
                        <span>
                            Their products have no purchase cost recorded, so
                            what they earned is unknown. Counting the{' '}
                            {formatBdt(excluded.revenue)} they sold for without
                            its cost would report the whole sale as profit.
                            Record a delivery with a unit cost and future orders
                            will be included.
                        </span>
                    </div>
                </div>
            )}

            <div className="admin-card admin-card-no-margin">
                <div className="admin-card-header">
                    <div>
                        <h3 className="admin-card-title">Statement</h3>
                        <span className="admin-table-item-sub">
                            {filters.from} to {filters.to}
                        </span>
                    </div>
                </div>

                {counted === 0 && expenses.total === 0 ? (
                    <div className="pl-blank">
                        <Wallet size={26} />
                        <strong>Nothing to report for this period</strong>
                        <span>
                            No orders with a recorded cost, and no expenses
                            entered. Pick a wider period, or record the shop's
                            running costs under Expenses.
                        </span>
                    </div>
                ) : (
                    <table className="pl-table">
                        <tbody>
                            <tr className="pl-section">
                                <th scope="row" colSpan={2}>
                                    Income
                                </th>
                            </tr>
                            <tr>
                                <td>Goods sold</td>
                                <td>{formatBdt(income.goods)}</td>
                            </tr>
                            <tr>
                                <td>
                                    Delivery collected
                                    <span className="admin-field-hint">
                                        Charged to customers; what the courier
                                        bills the shop sits in expenses
                                    </span>
                                </td>
                                <td>{formatBdt(income.delivery)}</td>
                            </tr>
                            <tr className="pl-subtotal">
                                <td>Total income</td>
                                <td>{formatBdt(income.total)}</td>
                            </tr>
                            {statement.vat_collected > 0 && (
                                <tr className="pl-passthrough">
                                    <td>
                                        VAT collected
                                        <span className="admin-field-hint">
                                            Held for the government, so it is
                                            not income — shown here to be
                                            checked against what is remitted
                                        </span>
                                    </td>
                                    <td>
                                        {formatBdt(statement.vat_collected)}
                                    </td>
                                </tr>
                            )}

                            <tr className="pl-section">
                                <th scope="row" colSpan={2}>
                                    Cost of goods sold
                                </th>
                            </tr>
                            <tr>
                                <td>
                                    What the goods sold cost
                                    <span className="admin-field-hint">
                                        Priced at the moment of sale — a
                                        delivery still on the shelf is not a
                                        cost yet
                                    </span>
                                </td>
                                <td className="is-negative">
                                    ({formatBdt(statement.cost_of_goods)})
                                </td>
                            </tr>
                            <tr className="pl-subtotal">
                                <td>Gross profit</td>
                                <td>{formatBdt(statement.gross_profit)}</td>
                            </tr>

                            <tr className="pl-section">
                                <th scope="row" colSpan={2}>
                                    Expenses
                                </th>
                            </tr>
                            {spent.length === 0 ? (
                                <tr>
                                    <td colSpan={2} className="pl-empty">
                                        Nothing recorded for this period.
                                    </td>
                                </tr>
                            ) : (
                                spent.map((c) => (
                                    <tr key={c.key}>
                                        <td>{c.label}</td>
                                        <td className="is-negative">
                                            ({formatBdt(c.amount)})
                                        </td>
                                    </tr>
                                ))
                            )}
                            <tr className="pl-subtotal">
                                <td>Total expenses</td>
                                <td className="is-negative">
                                    ({formatBdt(expenses.total)})
                                </td>
                            </tr>

                            <tr
                                className={`pl-total ${net >= 0 ? 'is-profit' : 'is-loss'}`}
                            >
                                <td>{net >= 0 ? 'Net profit' : 'Net loss'}</td>
                                <td>{formatBdt(net)}</td>
                            </tr>
                        </tbody>
                    </table>
                )}
            </div>
        </AdminLayout>
    );
}
