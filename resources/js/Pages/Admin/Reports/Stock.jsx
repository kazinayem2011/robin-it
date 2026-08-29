import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Tabs from '@/Components/Tabs';
import { AlertTriangle } from 'lucide-react';
import { formatBdt } from '@/utils/formatters';
import { Figure, Table, Unknown } from './Shared';
import './Reports.css';

/**
 * What the shop is holding and how long it has held it.
 *
 * The ageing half matters more here than in most trades. Computer parts
 * depreciate on a schedule nobody controls, so money sitting in stock that has
 * not moved in six months is the most expensive thing a small shop owns and the
 * least visible — it never appears on a profit and loss until the day it is
 * finally discounted.
 */
export default function StockReport({
    valuation = {},
    ageing = {},
    outOfStock = 0,
    stores = [],
    filters = {},
}) {
    const [tab, setTab] = useState('value');

    const slow = ageing.slow_value ?? 0;
    const total = ageing.total_value ?? 0;

    return (
        <AdminLayout
            title="Stock"
            subtitle="What is on the shelves, what it is worth, and how long it has sat there"
        >
            <Head title="Stock report" />

            {stores.length > 1 && (
                <div className="rep-period">
                    <div className="rep-period-shortcuts">
                        <button
                            type="button"
                            className={!filters.store ? 'is-on' : ''}
                            onClick={() => router.get('/admin/reports/stock')}
                        >
                            Whole shop
                        </button>
                        {stores.map((store) => (
                            <button
                                key={store.id}
                                type="button"
                                className={
                                    Number(filters.store) === store.id
                                        ? 'is-on'
                                        : ''
                                }
                                onClick={() =>
                                    router.get('/admin/reports/stock', {
                                        store: store.id,
                                    })
                                }
                            >
                                {store.name}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className="rep-figures">
                <Figure
                    label="On the shelves"
                    value={valuation.total_units}
                    hint="units"
                />
                <Figure
                    label="Worth, at cost"
                    value={valuation.total_value}
                    money
                />
                <Figure
                    label="Not moved in 2 months"
                    value={slow}
                    money
                    hint={
                        total > 0
                            ? `${Math.round((slow / total) * 100)}% of the shelf`
                            : null
                    }
                />
                <Figure
                    label="Listed but unbuyable"
                    value={outOfStock}
                    hint="active products at zero"
                />
            </div>

            {valuation.uncosted_lines > 0 && (
                <p className="rep-note">
                    <AlertTriangle size={13} /> {valuation.uncosted_lines} line
                    {valuation.uncosted_lines === 1 ? ' has' : 's have'} no
                    recorded cost, so
                    {valuation.uncosted_lines === 1
                        ? ' it is'
                        : ' they are'}{' '}
                    counted in the units and left out of the money. Valuing at
                    zero would understate the shelf; guessing would be worse.
                </p>
            )}

            <Tabs
                variant="enclosed"
                tabs={[
                    { key: 'value', label: 'What it is worth' },
                    {
                        key: 'ageing',
                        label: 'How long it has sat',
                        badge: ageing.lines?.length ?? 0,
                    },
                ]}
                activeTab={tab}
                onChange={setTab}
            />

            {tab === 'value' && (
                <div className="admin-card">
                    <div className="rep-split">
                        <div>
                            <h4>By category</h4>
                            <Table
                                columns={[
                                    { key: 'name', header: 'Category' },
                                    {
                                        key: 'units',
                                        header: 'Units',
                                        align: 'right',
                                    },
                                    {
                                        key: 'value',
                                        header: 'At cost',
                                        align: 'right',
                                        render: (r) => formatBdt(r.value),
                                    },
                                ]}
                                rows={valuation.by_category ?? []}
                            />
                        </div>

                        <div>
                            <h4>By branch</h4>
                            <Table
                                columns={[
                                    { key: 'name', header: 'Branch' },
                                    {
                                        key: 'units',
                                        header: 'Units',
                                        align: 'right',
                                    },
                                    {
                                        key: 'value',
                                        header: 'At cost',
                                        align: 'right',
                                        render: (r) => formatBdt(r.value),
                                    },
                                ]}
                                rows={valuation.by_branch ?? []}
                            />
                        </div>
                    </div>
                </div>
            )}

            {tab === 'ageing' && (
                <div className="admin-card">
                    {/*
                     * Bands rather than a single "slow" flag: a part that has
                     * not moved in three months is a discount, and one that has
                     * not moved in a year is a mistake. They need different
                     * answers, so they are shown as different rows.
                     */}
                    <div className="rep-buckets">
                        {(ageing.buckets ?? []).map((band) => (
                            <div key={band.label} className="rep-bucket">
                                <span>{band.label}</span>
                                <strong>{formatBdt(band.value)}</strong>
                                <small>
                                    {band.lines} line
                                    {band.lines === 1 ? '' : 's'} · {band.units}{' '}
                                    units
                                </small>
                            </div>
                        ))}
                    </div>

                    <Table
                        columns={[
                            { key: 'name', header: 'Product' },
                            { key: 'quantity', header: 'Held', align: 'right' },
                            {
                                key: 'value',
                                header: 'At cost',
                                align: 'right',
                                render: (r) =>
                                    r.value === null ? (
                                        <Unknown />
                                    ) : (
                                        formatBdt(r.value)
                                    ),
                            },
                            {
                                key: 'last_sold',
                                header: 'Last sold',
                                render: (r) =>
                                    r.ever_sold ? (
                                        r.last_sold
                                    ) : (
                                        <span className="rep-tag">never</span>
                                    ),
                            },
                            {
                                key: 'days_idle',
                                header: 'Days still',
                                align: 'right',
                                render: (r) =>
                                    r.days_idle === null ? (
                                        <Unknown />
                                    ) : (
                                        <strong
                                            className={
                                                r.days_idle >= 180
                                                    ? 'rep-alarm'
                                                    : ''
                                            }
                                        >
                                            {r.days_idle}
                                        </strong>
                                    ),
                            },
                        ]}
                        rows={ageing.lines ?? []}
                        empty="Nothing on the shelves."
                    />
                </div>
            )}
        </AdminLayout>
    );
}
