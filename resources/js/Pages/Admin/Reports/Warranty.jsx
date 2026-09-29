import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatBdt } from '@/utils/formatters';
import { PeriodPicker, Figure, Table, Unknown } from './Shared';
import './Reports.css';

/**
 * Warranty claims: how many, how fast, on what, and what replacements cost.
 *
 * Claims count by the day they were filed. Replacement cost counts by the day
 * the new unit left the shelf, the same as Stock lost on the profit and loss.
 */
export default function WarrantyReport({
    warranty = {},
    seesMoney = false,
    filters = {},
}) {
    const totals = warranty.totals ?? {};

    return (
        <AdminLayout
            title="Warranty"
            subtitle="Claims filed, how fast repairs come back, and which products fail"
        >
            <Head title="Warranty report" />

            <PeriodPicker filters={filters} path="/admin/reports/warranty" />

            <div className="rep-figures">
                <Figure label="Claims filed" value={totals.opened} />
                <Figure
                    label="Open now"
                    value={totals.open_now}
                    hint="on the bench today, whenever filed"
                />
                <Figure label="Completed" value={totals.completed} />
                <Figure label="Rejected" value={totals.rejected} />
                <Figure
                    label="Days to ready"
                    value={totals.average_days ?? 0}
                    hint={
                        totals.average_days === null
                            ? 'none ready yet'
                            : 'on average, filed to ready for pickup'
                    }
                />
                {seesMoney && (
                    <Figure
                        label="Replacement cost"
                        value={totals.replacement_cost}
                        money
                        hint={`${totals.replaced ?? 0} unit${totals.replaced === 1 ? '' : 's'} replaced, at cost`}
                    />
                )}
            </div>

            {totals.unknown_units > 0 && (
                <p className="rep-note">
                    {totals.unknown_units} of {totals.opened} claim
                    {totals.opened === 1 ? ' is' : 's are'} on a serial the shop
                    has no record of, so nobody could check them. Serial numbers
                    are asked for when stock with a warranty arrives; for stock
                    already on the shelf, add them under{' '}
                    <Link href="/admin/stock/serials">Serial numbers</Link>.
                </p>
            )}

            <div className="admin-card">
                <div className="rep-split">
                    <div>
                        <h4>Where the claims are now</h4>
                        <Table
                            columns={[
                                { key: 'label', header: 'Stage' },
                                {
                                    key: 'claims',
                                    header: 'Claims',
                                    align: 'right',
                                },
                            ]}
                            rows={(warranty.by_stage ?? []).map((s) => ({
                                ...s,
                                key: s.status,
                            }))}
                        />
                    </div>

                    <div>
                        <h4>Which products</h4>
                        <Table
                            columns={[
                                {
                                    key: 'name',
                                    header: 'Product',
                                    render: (r) => (
                                        <>
                                            {r.name}
                                            {!r.known && (
                                                <div className="rep-sub">
                                                    as the customer typed it
                                                </div>
                                            )}
                                        </>
                                    ),
                                },
                                {
                                    key: 'claims',
                                    header: 'Claims',
                                    align: 'right',
                                },
                                {
                                    key: 'replaced',
                                    header: 'Replaced',
                                    align: 'right',
                                },
                                {
                                    key: 'rejected',
                                    header: 'Rejected',
                                    align: 'right',
                                },
                            ]}
                            rows={(warranty.by_product ?? []).map((p) => ({
                                ...p,
                                key: p.name,
                            }))}
                            empty="No claims in this period."
                        />
                    </div>
                </div>
            </div>

            <div className="admin-card">
                <h4>Rejected, and why</h4>
                <Table
                    columns={[
                        { key: 'claim_number', header: 'Claim' },
                        {
                            key: 'product',
                            header: 'Product',
                            render: (r) => (
                                <>
                                    {r.product}
                                    <div className="rep-sub">{r.serial}</div>
                                </>
                            ),
                        },
                        {
                            key: 'why',
                            header: 'Reason given',
                            render: (r) =>
                                r.why ? r.why : <Unknown>no note</Unknown>,
                        },
                        { key: 'on', header: 'On' },
                    ]}
                    rows={(warranty.rejected ?? []).map((r) => ({
                        ...r,
                        key: r.claim_number,
                    }))}
                    empty="Nothing rejected in this period."
                />
            </div>

            {seesMoney && (
                <p className="rep-note">
                    Replacement cost is what the new units cost the shop. It is
                    also inside Stock lost on the profit and loss — shown here
                    so warranty is visible on its own. Worth{' '}
                    {formatBdt(totals.replacement_cost ?? 0)} this period.
                </p>
            )}
        </AdminLayout>
    );
}
