import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Tabs from '@/Components/Tabs';
import { formatBdt } from '@/utils/formatters';
import { PeriodPicker, Figure, Bars, Table, Unknown } from './Shared';
import './Reports.css';

/**
 * What sold, which products earned, and who bought.
 *
 * One screen because they are one question asked three ways: a shop looking at
 * a good month immediately wants to know what drove it and whether the same
 * people are coming back.
 */
export default function SalesReport({
    sales = {},
    products = {},
    neverSold = [],
    customers = {},
    lapsed = [],
    filters = {},
}) {
    const [tab, setTab] = useState('overview');
    const totals = sales.totals ?? {};
    const previous = sales.previous ?? {};

    return (
        <AdminLayout
            title="Sales"
            subtitle="What sold, and whether that is better than before"
        >
            <Head title="Sales report" />

            <PeriodPicker filters={filters} path="/admin/reports/sales" />

            <div className="rep-figures">
                <Figure
                    label="Revenue"
                    value={totals.revenue}
                    previous={previous.revenue}
                    money
                />
                <Figure
                    label="Orders"
                    value={totals.orders}
                    previous={previous.orders}
                />
                <Figure
                    label="Units"
                    value={totals.units}
                    previous={previous.units}
                />
                <Figure
                    label="Average order"
                    value={totals.average_order}
                    previous={previous.average_order}
                    money
                />
                <Figure
                    label="Refunded"
                    value={totals.refunded}
                    previous={previous.refunded}
                    money
                    hint="By the date the money moved"
                />
                <Figure
                    label="Net"
                    value={totals.net}
                    previous={previous.net}
                    money
                />
            </div>

            <p className="rep-note">
                Against {sales.previous_period?.from} to{' '}
                {sales.previous_period?.to} — the same length of time
                immediately before, so the comparison means something.
            </p>

            <Tabs
                variant="enclosed"
                tabs={[
                    { key: 'overview', label: 'Day by day' },
                    {
                        key: 'products',
                        label: 'Products',
                        badge: products.lines?.length ?? 0,
                    },
                    {
                        key: 'idle',
                        label: 'Not selling',
                        badge: neverSold.length,
                    },
                    {
                        key: 'customers',
                        label: 'Customers',
                        badge: customers.top?.length ?? 0,
                    },
                    {
                        key: 'lapsed',
                        label: 'Gone quiet',
                        badge: lapsed.length,
                    },
                ]}
                activeTab={tab}
                onChange={setTab}
            />

            {tab === 'overview' && (
                <div className="admin-card">
                    <Bars series={sales.series ?? []} />

                    <div className="rep-split">
                        <div>
                            {/*
                             * Every order placed in the period, cancelled and
                             * returned included — this is the one place they
                             * are the point, and a rising cancellation count is
                             * the thing worth seeing. It will not add up to the
                             * order count above, which counts sales only.
                             */}
                            <h4>Every order placed, however it ended</h4>
                            <Table
                                columns={[
                                    { key: 'status', header: 'Status' },
                                    {
                                        key: 'count',
                                        header: 'Orders',
                                        align: 'right',
                                    },
                                ]}
                                rows={Object.entries(sales.by_status ?? {}).map(
                                    ([status, count]) => ({
                                        key: status,
                                        status,
                                        count,
                                    }),
                                )}
                            />
                        </div>

                        <div>
                            <h4>How they paid</h4>
                            <Table
                                columns={[
                                    { key: 'method', header: 'Method' },
                                    {
                                        key: 'orders',
                                        header: 'Orders',
                                        align: 'right',
                                    },
                                    {
                                        key: 'revenue',
                                        header: 'Value',
                                        align: 'right',
                                        render: (r) => formatBdt(r.revenue),
                                    },
                                ]}
                                rows={sales.by_payment ?? []}
                            />
                        </div>
                    </div>
                </div>
            )}

            {tab === 'products' && (
                <div className="admin-card">
                    {products.uncosted > 0 && (
                        <p className="rep-note">
                            {products.uncosted} product
                            {products.uncosted === 1 ? '' : 's'} sold with no
                            recorded cost, so no margin is shown for{' '}
                            {products.uncosted === 1 ? 'it' : 'them'}. Receive
                            stock with a unit cost and it appears here.
                        </p>
                    )}

                    <Table
                        columns={[
                            { key: 'name', header: 'Product' },
                            { key: 'units', header: 'Units', align: 'right' },
                            {
                                key: 'revenue',
                                header: 'Revenue',
                                align: 'right',
                                render: (r) => formatBdt(r.revenue),
                            },
                            {
                                key: 'profit',
                                header: 'Profit',
                                align: 'right',
                                render: (r) =>
                                    r.profit === null ? (
                                        <Unknown />
                                    ) : (
                                        formatBdt(r.profit)
                                    ),
                            },
                            {
                                key: 'margin_percent',
                                header: 'Margin',
                                align: 'right',
                                render: (r) =>
                                    r.margin_percent === null ? (
                                        <Unknown />
                                    ) : (
                                        `${r.margin_percent}%`
                                    ),
                            },
                            {
                                key: 'in_stock',
                                header: 'Left',
                                align: 'right',
                                render: (r) =>
                                    r.in_stock === null ? (
                                        <Unknown />
                                    ) : (
                                        r.in_stock
                                    ),
                            },
                        ]}
                        rows={products.lines ?? []}
                    />
                </div>
            )}

            {tab === 'idle' && (
                <div className="admin-card">
                    <p className="rep-note">
                        Listed, in stock, and not sold once in this period. The
                        more expensive half of the question: a best-seller list
                        says what to reorder, this says what to stop buying.
                    </p>

                    <Table
                        columns={[
                            { key: 'name', header: 'Product' },
                            { key: 'category', header: 'Category' },
                            {
                                key: 'in_stock',
                                header: 'On the shelf',
                                align: 'right',
                            },
                            {
                                key: 'tied_up',
                                header: 'Tied up',
                                align: 'right',
                                render: (r) => formatBdt(r.tied_up),
                            },
                        ]}
                        rows={neverSold}
                        empty="Everything in stock sold at least once. Unusual, and good."
                    />
                </div>
            )}

            {tab === 'customers' && (
                <div className="admin-card">
                    <div className="rep-figures is-compact">
                        <Figure
                            label="Customers"
                            value={customers.totals?.customers}
                        />
                        <Figure
                            label="New"
                            value={customers.new_vs_returning?.new}
                        />
                        <Figure
                            label="Returning"
                            value={customers.new_vs_returning?.returning}
                        />
                        <Figure
                            label="Average spend"
                            value={customers.totals?.average_per_customer}
                            money
                        />
                        <Figure
                            label="Orders each"
                            value={customers.totals?.orders_per_customer}
                        />
                    </div>

                    <Table
                        columns={[
                            {
                                key: 'name',
                                header: 'Customer',
                                render: (r) => (
                                    <div>
                                        <strong>{r.name ?? 'Not given'}</strong>
                                        <div className="rep-sub">
                                            {r.phone ?? r.email ?? '—'}
                                            {!r.has_account && ' · guest'}
                                        </div>
                                    </div>
                                ),
                            },
                            { key: 'orders', header: 'Orders', align: 'right' },
                            {
                                key: 'spent',
                                header: 'Spent',
                                align: 'right',
                                render: (r) => formatBdt(r.spent),
                            },
                            {
                                key: 'last_order',
                                header: 'Last order',
                                align: 'right',
                            },
                        ]}
                        rows={customers.top ?? []}
                    />
                </div>
            )}

            {tab === 'lapsed' && (
                <div className="admin-card">
                    <p className="rep-note">
                        Customers who bought before and have not been back in
                        four months. Worth a message far more than a stranger on
                        the mailing list — though only the ones marked reachable
                        have agreed to hear from you.
                    </p>

                    <Table
                        columns={[
                            { key: 'name', header: 'Customer' },
                            { key: 'orders', header: 'Orders', align: 'right' },
                            {
                                key: 'spent',
                                header: 'Spent',
                                align: 'right',
                                render: (r) => formatBdt(r.spent),
                            },
                            {
                                key: 'last_order',
                                header: 'Last seen',
                                align: 'right',
                            },
                            {
                                key: 'reachable',
                                header: '',
                                align: 'right',
                                render: (r) =>
                                    r.reachable ? (
                                        <span className="rep-tag is-ok">
                                            reachable
                                        </span>
                                    ) : (
                                        <span className="rep-tag">
                                            opted out
                                        </span>
                                    ),
                            },
                        ]}
                        rows={lapsed}
                        empty="Nobody has gone quiet. Everyone who has bought has been back."
                    />
                </div>
            )}
        </AdminLayout>
    );
}
