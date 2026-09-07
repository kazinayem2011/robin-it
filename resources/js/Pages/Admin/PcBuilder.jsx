import React from 'react';
import { Link } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { ROUTES } from '../../constants/endpoints';
import { AlertTriangle, CheckCircle2, Cpu } from 'lucide-react';
import './PcBuilder.css';

/**
 * What the PC Builder is offering, and why.
 *
 * None of the rules behind the builder are visible anywhere else in the admin.
 * A part reaches a slot because of the category it is filed under and its
 * Active tick — not its stock, and not any switch on the builder itself.
 * Compatibility is checked from specifications whose names have to match, and
 * a missing one counts as "unknown" rather than a failure, so a build nobody
 * could check looks exactly like one that passed.
 *
 * This screen writes nothing. Every number on it is changed somewhere that
 * already exists — on the product, or on the category it sits in — so each row
 * says which, rather than offering a second place to edit the same thing.
 */
export default function AdminPcBuilder({ slots = [], summary = {} }) {
    const trouble = (slot) => slot.starved || slot.missing_specs > 0;

    return (
        <AdminLayout
            title="PC Builder"
            subtitle="Which parts each slot offers, and what stops one appearing"
        >
            <Head title="PC Builder" />

            <div className="admin-pcb">
                <div className="admin-pcb-intro">
                    <Cpu size={18} />
                    <div>
                        <strong>How a part reaches the builder</strong>
                        <p>
                            A product appears in a slot when it sits in that
                            slot&apos;s category and is ticked Active. Stock is
                            not a filter — an out-of-stock part is still
                            offered, labelled. To take something out of the
                            builder, untick Active or move it to another
                            category.
                        </p>
                    </div>
                </div>

                {summary.spec_gaps > 0 && (
                    <div className="admin-pcb-banner">
                        <AlertTriangle size={16} />
                        <span>
                            <strong>
                                {summary.spec_gaps} part
                                {summary.spec_gaps === 1 ? '' : 's'}
                            </strong>{' '}
                            cannot be compatibility-checked, because the
                            specifications the check reads are missing. Those
                            builds are reported as unverified rather than as
                            passing.
                        </span>
                    </div>
                )}

                <table className="admin-pcb-table">
                    <thead>
                        <tr>
                            <th>Slot</th>
                            <th>Filled from</th>
                            <th className="num">Parts</th>
                            <th className="num">In stock</th>
                            <th className="num">Checkable</th>
                            <th>Needs these specs</th>
                        </tr>
                    </thead>
                    <tbody>
                        {slots.map((slot) => (
                            <tr
                                key={slot.id}
                                className={trouble(slot) ? 'has-trouble' : ''}
                            >
                                <td>
                                    <strong>{slot.label}</strong>
                                    {slot.required && (
                                        <span className="admin-pcb-required">
                                            required
                                        </span>
                                    )}
                                </td>

                                <td>
                                    <code>{slot.category}</code>
                                </td>

                                <td className="num">
                                    {/* A required slot with nothing in it is
                                        the one state that actually stops a
                                        customer finishing a build, so it is
                                        named rather than left a bare zero. */}
                                    {slot.starved ? (
                                        <span className="admin-pcb-warn">
                                            <AlertTriangle size={13} /> none
                                        </span>
                                    ) : (
                                        slot.parts
                                    )}
                                    {slot.over_cap && (
                                        <span
                                            className="admin-pcb-warn"
                                            title={`Only the newest ${slot.shown} are offered`}
                                        >
                                            {' '}
                                            showing {slot.shown}
                                        </span>
                                    )}
                                </td>

                                <td className="num">
                                    {/* "none" rather than a zero with a word
                                        after it, which rendered as "0none". */}
                                    {slot.parts > 0 && slot.in_stock === 0 ? (
                                        <span className="admin-pcb-warn">
                                            none
                                        </span>
                                    ) : (
                                        slot.in_stock
                                    )}
                                </td>

                                <td className="num">
                                    {slot.needs_specs.length === 0 ? (
                                        <span className="admin-pcb-muted">
                                            n/a
                                        </span>
                                    ) : slot.missing_specs === 0 ? (
                                        <span className="admin-pcb-ok">
                                            <CheckCircle2 size={13} /> all
                                        </span>
                                    ) : (
                                        <span className="admin-pcb-warn">
                                            {slot.parts - slot.missing_specs} of{' '}
                                            {slot.parts}
                                        </span>
                                    )}
                                </td>

                                <td>
                                    {slot.needs_specs.length ? (
                                        slot.needs_specs.join(', ')
                                    ) : (
                                        <span className="admin-pcb-muted">
                                            not compatibility-checked
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <p className="admin-pcb-foot">
                    Specifications are edited on the product.{' '}
                    <Link href={ROUTES.ADMIN_PRODUCTS}>Open Products</Link> —
                    the list flags each one that is missing a spec the builder
                    needs.
                </p>
            </div>
        </AdminLayout>
    );
}
