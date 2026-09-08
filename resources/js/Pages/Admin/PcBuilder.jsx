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
                {/*
                 * Written for whoever actually keeps the catalogue, who has
                 * no reason to know what a category slug is. It names the
                 * exact fields on the product form — Group, Name, Value — and
                 * gives values that can be copied, because "add a socket
                 * specification" is not an instruction anyone can follow if
                 * the check is matching on a field they cannot see.
                 *
                 * Open by default: the whole reason this screen exists is
                 * that none of it was written down anywhere.
                 */}
                <details className="admin-pcb-guide" open>
                    <summary>
                        <Cpu size={16} />
                        <span>How the PC Builder picks up your products</span>
                    </summary>

                    <div className="admin-pcb-guide-body">
                        <section>
                            <h3>To put a product in the builder</h3>
                            <ol>
                                <li>
                                    Open the product in{' '}
                                    <strong>Products</strong>.
                                </li>
                                <li>
                                    Set its <strong>Category</strong> to the one
                                    named in the <em>Filled from</em> column
                                    below — a processor goes in the processor
                                    category, and so on.
                                </li>
                                <li>
                                    Make sure <strong>Active</strong> is ticked.
                                    That is the only switch involved.
                                </li>
                            </ol>
                            <p className="admin-pcb-note">
                                Being out of stock does <strong>not</strong>{' '}
                                hide a part. It still appears, marked out of
                                stock, so a customer can plan a build around
                                something you are restocking. To take a part out
                                of the builder altogether, untick Active or move
                                it to a different category.
                            </p>
                        </section>

                        <section>
                            <h3>To make the compatibility check work</h3>
                            <p>
                                The builder warns a customer when two parts do
                                not fit — a processor and a motherboard with
                                different sockets, for instance. It can only do
                                that when the parts carry the right{' '}
                                <strong>Specifications</strong>. Without them it
                                says &ldquo;could not confirm&rdquo; rather than
                                passing or failing the build.
                            </p>
                            <p>
                                On the product form, each specification row has
                                three boxes. Only the middle one has to match
                                exactly:
                            </p>
                            <table className="admin-pcb-example">
                                <thead>
                                    <tr>
                                        <th>Group</th>
                                        <th>Name — must match</th>
                                        <th>Value — yours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Processor</td>
                                        <td>
                                            <code>Socket</code>
                                        </td>
                                        <td>AM5</td>
                                    </tr>
                                    <tr>
                                        <td>Processor</td>
                                        <td>
                                            <code>TDP</code>
                                        </td>
                                        <td>120W</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p className="admin-pcb-note">
                                The <em>Needs these specs</em> column below
                                tells you which names each kind of part wants.
                                Write wattages with the W — <code>120W</code>,
                                not <code>120</code> — or the power estimate
                                cannot read them.
                            </p>
                        </section>

                        <section>
                            <h3>Reading the table</h3>
                            <ul>
                                <li>
                                    <strong>Parts</strong> — how many products a
                                    customer can choose from here.{' '}
                                    <span className="admin-pcb-warn">none</span>{' '}
                                    on a required row means nobody can finish a
                                    build.
                                </li>
                                <li>
                                    <strong>Checkable</strong> — how many of
                                    those carry the specifications the
                                    compatibility check reads. The rest are
                                    reported to the customer as unverified.
                                </li>
                                <li>
                                    <strong>n/a</strong> — nothing to check on
                                    this kind of part. A mouse cannot clash with
                                    anything.
                                </li>
                            </ul>
                        </section>
                    </div>
                </details>

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
