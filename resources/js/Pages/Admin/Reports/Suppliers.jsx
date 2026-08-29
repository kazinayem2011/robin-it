import React from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatBdt } from '@/utils/formatters';
import { PeriodPicker, Figure, Table, Unknown } from './Shared';
import './Reports.css';

/**
 * Who sends what they promised, when they promised it.
 *
 * Only answerable since purchase orders existed. Receipts recorded what turned
 * up, and with no record of what was asked for, a supplier who habitually ships
 * eighteen against twenty looked exactly like one who ships twenty.
 */
export default function SupplierReport({ suppliers = {}, filters = {} }) {
    const totals = suppliers.totals ?? {};
    const outstanding = suppliers.outstanding ?? [];
    const late = outstanding.filter((o) => o.days_overdue > 0);

    return (
        <AdminLayout
            title="Suppliers"
            subtitle="Who sends what they promised, when they promised it"
        >
            <Head title="Supplier report" />

            <PeriodPicker filters={filters} path="/admin/reports/suppliers" />

            <div className="rep-figures">
                <Figure label="Orders placed" value={totals.orders} />
                <Figure label="Units ordered" value={totals.units_ordered} />
                <Figure label="Units received" value={totals.units_received} />
                <Figure
                    label="Fill rate"
                    value={totals.fill_rate ?? 0}
                    hint="how much of what you asked for arrived"
                />
                <Figure label="Value ordered" value={totals.value} money />
                <Figure label="Overdue orders" value={late.length} />
            </div>

            <div className="admin-card">
                <h4>By supplier</h4>
                <Table
                    columns={[
                        { key: 'name', header: 'Supplier' },
                        { key: 'orders', header: 'Orders', align: 'right' },
                        {
                            key: 'units_ordered',
                            header: 'Ordered',
                            align: 'right',
                        },
                        {
                            key: 'units_received',
                            header: 'Arrived',
                            align: 'right',
                        },
                        {
                            key: 'still_owed',
                            header: 'Still owed',
                            align: 'right',
                            render: (r) =>
                                r.still_owed > 0 ? (
                                    <strong className="rep-alarm">
                                        {r.still_owed}
                                    </strong>
                                ) : (
                                    '—'
                                ),
                        },
                        {
                            key: 'fill_rate',
                            header: 'Fill rate',
                            align: 'right',
                            render: (r) =>
                                r.fill_rate === null ? (
                                    <Unknown />
                                ) : (
                                    <strong
                                        className={
                                            r.fill_rate < 95 ? 'rep-alarm' : ''
                                        }
                                    >
                                        {r.fill_rate}%
                                    </strong>
                                ),
                        },
                        {
                            key: 'average_days_late',
                            header: 'Days late',
                            align: 'right',
                            render: (r) =>
                                r.average_days_late === null ? (
                                    <Unknown>no date promised</Unknown>
                                ) : (
                                    r.average_days_late
                                ),
                        },
                        {
                            key: 'value',
                            header: 'Value',
                            align: 'right',
                            render: (r) => formatBdt(r.value),
                        },
                    ]}
                    rows={suppliers.suppliers ?? []}
                    empty="No purchase orders were placed in this period."
                />
            </div>

            <div className="admin-card">
                <h4>Still coming</h4>
                <p className="rep-note">
                    Everything on order, whenever it was placed — this list is
                    not limited to the period above, because a delivery that is
                    four months late is exactly what you would miss by filtering
                    it out.
                </p>

                <Table
                    columns={[
                        { key: 'reference', header: 'Order' },
                        { key: 'supplier', header: 'Supplier' },
                        {
                            key: 'expected_on',
                            header: 'Promised',
                            render: (r) =>
                                r.expected_on ?? <Unknown>no date</Unknown>,
                        },
                        {
                            key: 'days_overdue',
                            header: 'Overdue by',
                            align: 'right',
                            render: (r) =>
                                r.days_overdue > 0 ? (
                                    <strong className="rep-alarm">
                                        {r.days_overdue} days
                                    </strong>
                                ) : (
                                    '—'
                                ),
                        },
                        {
                            key: 'outstanding',
                            header: 'Units owed',
                            align: 'right',
                        },
                    ]}
                    rows={outstanding}
                    empty="Nothing outstanding. Every order has arrived in full."
                />
            </div>
        </AdminLayout>
    );
}
