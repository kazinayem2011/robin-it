import React from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatBdt } from '@/utils/formatters';
import { PeriodPicker, Figure, Table, Unknown } from './Shared';
import './Reports.css';

/**
 * Which courier actually delivers.
 *
 * A shop picks a carrier on price and finds out about the rest afterwards, one
 * angry phone call at a time. The orders table already held the answer and
 * nothing was reading it.
 */
export default function DeliveryReport({ delivery = {}, filters = {} }) {
    const totals = delivery.totals ?? {};

    return (
        <AdminLayout
            title="Delivery"
            subtitle="Which courier delivers, how often, and how fast"
        >
            <Head title="Delivery report" />

            <PeriodPicker filters={filters} path="/admin/reports/delivery" />

            <div className="rep-figures">
                <Figure label="Parcels" value={totals.parcels} />
                <Figure label="Delivered" value={totals.delivered} />
                <Figure label="Came back" value={totals.returned} />
                <Figure
                    label="Delivery rate"
                    value={totals.delivery_rate ?? 0}
                    hint="of the ones that finished"
                />
                <Figure
                    label="Average days"
                    value={totals.average_days ?? 0}
                    hint="from dispatch, not from the order"
                />
                <Figure
                    label="Not yet booked"
                    value={delivery.undispatched}
                    hint="sitting in the shop"
                />
            </div>

            <p className="rep-note">
                Rates count only parcels that have finished their journey.
                Including the ones still in transit would make a courier look
                worse the busier the shop has been, which measures the shop
                rather than them. Days are counted from handing the parcel over,
                so your own picking time is not blamed on the carrier.
            </p>

            <div className="admin-card">
                <Table
                    columns={[
                        { key: 'name', header: 'Courier' },
                        { key: 'parcels', header: 'Parcels', align: 'right' },
                        {
                            key: 'delivered',
                            header: 'Delivered',
                            align: 'right',
                        },
                        { key: 'returned', header: 'Returned', align: 'right' },
                        {
                            key: 'in_transit',
                            header: 'In transit',
                            align: 'right',
                        },
                        {
                            key: 'delivery_rate',
                            header: 'Delivered',
                            align: 'right',
                            render: (r) =>
                                r.delivery_rate === null ? (
                                    <Unknown>nothing settled yet</Unknown>
                                ) : (
                                    <strong
                                        className={
                                            r.delivery_rate < 90
                                                ? 'rep-alarm'
                                                : ''
                                        }
                                    >
                                        {r.delivery_rate}%
                                    </strong>
                                ),
                        },
                        {
                            key: 'average_days',
                            header: 'Days',
                            align: 'right',
                            render: (r) =>
                                r.average_days === null ? (
                                    <Unknown />
                                ) : (
                                    r.average_days
                                ),
                        },
                        {
                            key: 'value',
                            header: 'Value carried',
                            align: 'right',
                            render: (r) => formatBdt(r.value),
                        },
                    ]}
                    rows={delivery.couriers ?? []}
                    empty="No parcels went out with a courier in this period."
                />
            </div>
        </AdminLayout>
    );
}
