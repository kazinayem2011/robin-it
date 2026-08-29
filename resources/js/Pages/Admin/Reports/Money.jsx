import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Tabs from '@/Components/Tabs';
import { Truck } from 'lucide-react';
import { formatBdt } from '@/utils/formatters';
import { PeriodPicker, Figure, Table } from './Shared';
import { ROUTES } from '@/constants/endpoints';
import './Reports.css';

/**
 * What is owed, what is owed to the government, and what went back.
 *
 * A shop taking deposits and cash on delivery is lending money to its own
 * orders. Every order knows what it is owed and nothing added them up, so the
 * total — often the difference between paying suppliers this week and not —
 * appeared on no screen at all.
 */
export default function MoneyReport({
    owed = {},
    vat = {},
    refunds = {},
    filters = {},
}) {
    const [tab, setTab] = useState('owed');

    return (
        <AdminLayout
            title="Money"
            subtitle="What is owed, what is owed onward, and what went back"
        >
            <Head title="Money report" />

            <Tabs
                variant="enclosed"
                tabs={[
                    {
                        key: 'owed',
                        label: 'Still owed',
                        badge: owed.orders ?? 0,
                    },
                    { key: 'vat', label: 'VAT' },
                    {
                        key: 'refunds',
                        label: 'Refunds',
                        badge: refunds.count ?? 0,
                    },
                ]}
                activeTab={tab}
                onChange={setTab}
            />

            {tab === 'owed' && (
                <>
                    {/* A position, not a period: what is outstanding today does
                        not belong to a date range, so no picker here. */}
                    <div className="rep-figures">
                        <Figure
                            label="Owed to the shop"
                            value={owed.total}
                            money
                        />
                        <Figure label="Orders" value={owed.orders} />
                        <Figure
                            label="Out with a courier"
                            value={owed.with_courier?.amount}
                            money
                            hint={`${owed.with_courier?.orders ?? 0} parcels dispatched unpaid`}
                        />
                    </div>

                    <div className="admin-card">
                        <div className="rep-buckets">
                            {(owed.buckets ?? []).map((band) => (
                                <div key={band.label} className="rep-bucket">
                                    <span>{band.label}</span>
                                    <strong>{formatBdt(band.amount)}</strong>
                                    <small>
                                        {band.orders} order
                                        {band.orders === 1 ? '' : 's'}
                                    </small>
                                </div>
                            ))}
                        </div>

                        <Table
                            columns={[
                                {
                                    key: 'order_number',
                                    header: 'Order',
                                    render: (r) => (
                                        <div>
                                            <Link href={ROUTES.ADMIN_ORDERS}>
                                                <strong>
                                                    {r.order_number}
                                                </strong>
                                            </Link>
                                            <div className="rep-sub">
                                                {r.customer}
                                            </div>
                                        </div>
                                    ),
                                },
                                { key: 'status', header: 'Status' },
                                {
                                    key: 'courier',
                                    header: 'With',
                                    render: (r) =>
                                        r.with_courier ? (
                                            <span className="rep-tag is-warn">
                                                <Truck size={11} />{' '}
                                                {r.courier ?? 'courier'}
                                            </span>
                                        ) : (
                                            '—'
                                        ),
                                },
                                {
                                    key: 'paid',
                                    header: 'Paid',
                                    align: 'right',
                                    render: (r) => formatBdt(r.paid),
                                },
                                {
                                    key: 'due',
                                    header: 'Owed',
                                    align: 'right',
                                    render: (r) => (
                                        <strong>{formatBdt(r.due)}</strong>
                                    ),
                                },
                                { key: 'days', header: 'Days', align: 'right' },
                            ]}
                            rows={owed.lines ?? []}
                            empty="Nothing outstanding. Every order is settled."
                        />
                    </div>
                </>
            )}

            {tab === 'vat' && (
                <>
                    <PeriodPicker
                        filters={filters}
                        path="/admin/reports/money"
                    />

                    {!vat.enabled ? (
                        <p className="rep-empty">
                            VAT is switched off under Settings → VAT, so nothing
                            is being collected.
                        </p>
                    ) : (
                        <>
                            <div className="rep-figures">
                                <Figure
                                    label="Collected"
                                    value={vat.collected}
                                    money
                                />
                                <Figure
                                    label="On refunds"
                                    value={vat.refunded}
                                    money
                                    hint="reclaimable"
                                />
                                <Figure
                                    label="Net owed"
                                    value={vat.net}
                                    money
                                />
                                <Figure
                                    label="Rate"
                                    value={vat.rate}
                                    hint={
                                        vat.inclusive
                                            ? 'inside the price'
                                            : 'added on top'
                                    }
                                />
                            </div>

                            <p className="rep-note">
                                Collected for the government and owed to it —
                                money passing through, not income, which is why
                                it is not in the profit and loss.
                                {vat.registration &&
                                    ` Registration ${vat.registration}.`}
                            </p>

                            <div className="admin-card">
                                <Table
                                    columns={[
                                        { key: 'month', header: 'Month' },
                                        {
                                            key: 'orders',
                                            header: 'Orders',
                                            align: 'right',
                                        },
                                        {
                                            key: 'goods',
                                            header: 'Goods',
                                            align: 'right',
                                            render: (r) => formatBdt(r.goods),
                                        },
                                        {
                                            key: 'vat',
                                            header: 'VAT',
                                            align: 'right',
                                            render: (r) => (
                                                <strong>
                                                    {formatBdt(r.vat)}
                                                </strong>
                                            ),
                                        },
                                    ]}
                                    rows={vat.by_month ?? []}
                                />
                            </div>
                        </>
                    )}
                </>
            )}

            {tab === 'refunds' && (
                <>
                    <PeriodPicker
                        filters={filters}
                        path="/admin/reports/money"
                    />

                    <div className="rep-figures">
                        <Figure label="Refunded" value={refunds.total} money />
                        <Figure label="Refunds" value={refunds.count} />
                    </div>

                    <div className="admin-card">
                        <div className="rep-split">
                            <div>
                                <h4>Why</h4>
                                {/* The useful part: a month of "arrived damaged" is a
                                    courier conversation, a month of "not as described"
                                    is a listing to rewrite. */}
                                <Table
                                    columns={[
                                        { key: 'label', header: 'Reason' },
                                        {
                                            key: 'count',
                                            header: 'Count',
                                            align: 'right',
                                        },
                                        {
                                            key: 'amount',
                                            header: 'Value',
                                            align: 'right',
                                            render: (r) => formatBdt(r.amount),
                                        },
                                    ]}
                                    rows={refunds.by_reason ?? []}
                                />
                            </div>

                            <div>
                                <h4>How it went back</h4>
                                <Table
                                    columns={[
                                        { key: 'label', header: 'Method' },
                                        {
                                            key: 'count',
                                            header: 'Count',
                                            align: 'right',
                                        },
                                        {
                                            key: 'amount',
                                            header: 'Value',
                                            align: 'right',
                                            render: (r) => formatBdt(r.amount),
                                        },
                                    ]}
                                    rows={refunds.by_method ?? []}
                                />
                            </div>
                        </div>
                    </div>
                </>
            )}
        </AdminLayout>
    );
}
