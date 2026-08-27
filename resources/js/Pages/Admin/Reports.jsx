import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { AlertTriangle, TrendingUp } from 'lucide-react';
import FormInput from '@/Components/FormInput';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Reports.css';

/**
 * Profit and loss for a period.
 *
 * The two sides come from different places, deliberately:
 *
 *   Cost of goods sold is priced from the order lines — what the units that
 *   *sold* cost the shop. Not what was bought in the period: a delivery still
 *   on the shelf has not cost anything yet, it has turned cash into stock.
 *
 *   Everything under Expenses is money that left and did not come back as
 *   something sellable.
 */
export default function AdminReports({ statement = {}, filters = {} }) {
    const setPeriod = (patch) =>
        router.get(
            ROUTES.ADMIN_REPORTS_PROFIT,
            { ...filters, ...patch },
            { preserveState: true, replace: true },
        );

    const income = statement.income || {};
    const expenses = statement.expenses || { total: 0, by_category: [] };
    const excluded = statement.excluded || { orders: 0, revenue: 0 };
    const net = statement.net_profit ?? 0;

    // Only the categories that were actually spent on; a statement listing
    // eight zeroes buries the two lines that matter.
    const spent = (expenses.by_category || []).filter((c) => c.amount > 0);

    return (
        <AdminLayout
            title="Profit & Loss"
            subtitle="What the shop earned and what it spent"
        >
            <Head title="Profit & Loss" />

            <div className="admin-input-row-flex admin-order-actions">
                <FormInput
                    label="From"
                    name="from"
                    type="date"
                    value={filters.from || ''}
                    onChange={(e) => setPeriod({ from: e.target.value })}
                />
                <FormInput
                    label="To"
                    name="to"
                    type="date"
                    value={filters.to || ''}
                    onChange={(e) => setPeriod({ to: e.target.value })}
                />
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
                        <h3 className="admin-card-title">
                            Statement for {filters.from} to {filters.to}
                        </h3>
                        <span className="admin-table-item-sub">
                            Across {statement.orders_counted ?? 0} order
                            {statement.orders_counted === 1 ? '' : 's'} with a
                            known cost
                        </span>
                    </div>
                    <div
                        className={`pl-headline ${net >= 0 ? 'is-profit' : 'is-loss'}`}
                    >
                        <TrendingUp size={18} />
                        <span>{formatBdt(net)}</span>
                    </div>
                </div>

                <div className="admin-card-body">
                    <table className="pl-table">
                        <tbody>
                            <tr className="pl-section">
                                <th colSpan={2}>Income</th>
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

                            <tr className="pl-section">
                                <th colSpan={2}>Cost of goods sold</th>
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
                                <td>
                                    Gross profit
                                    {statement.gross_margin_percent !==
                                        null && (
                                        <span className="admin-field-hint">
                                            {statement.gross_margin_percent}% of
                                            goods sold
                                        </span>
                                    )}
                                </td>
                                <td>{formatBdt(statement.gross_profit)}</td>
                            </tr>

                            <tr className="pl-section">
                                <th colSpan={2}>Expenses</th>
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
                                <td>
                                    {net >= 0 ? 'Net profit' : 'Net loss'}
                                    {statement.net_margin_percent !== null && (
                                        <span className="admin-field-hint">
                                            {statement.net_margin_percent}% of
                                            total income
                                        </span>
                                    )}
                                </td>
                                <td>{formatBdt(net)}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
