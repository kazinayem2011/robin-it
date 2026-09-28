import React from 'react';
import { Link } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { ROUTES } from '../../constants/endpoints';
import { AlertTriangle, CheckCircle2, Cpu } from 'lucide-react';
import PcBuilderParts from './Components/PcBuilderParts';
import './PcBuilder.css';

/**
 * The PC Builder: its parts, and what is stopping customers.
 *
 * One table of parts, with what each offers and what its products are
 * missing, under a line that says whether anything needs doing. The page used
 * to list the same parts twice — once to manage them and again, in a fold, to
 * report on them — and the second list only knew each part's first category.
 */
export default function AdminPcBuilder({
    problems = [],
    parts = [],
    icons = [],
}) {
    return (
        <AdminLayout
            title="PC Builder"
            subtitle="The parts customers choose from, and what is stopping them"
        >
            <Head title="PC Builder" />

            <div className="admin-pcb">
                {/* What needs doing, first. Most days, nothing. */}
                {problems.length === 0 ? (
                    <p className="admin-pcb-clear">
                        <CheckCircle2 size={16} /> Nothing needs attention.
                        Every required part has products to choose from, and
                        they carry the details the builder needs to check a
                        build fits together.
                    </p>
                ) : (
                    <ul className="admin-pcb-problems">
                        {problems.map((problem) => (
                            <li
                                key={problem.title}
                                className={`tone-${problem.tone}`}
                            >
                                <AlertTriangle size={16} />
                                <div>
                                    <strong>{problem.title}</strong>
                                    <p>{problem.detail}</p>
                                </div>
                                <Link href={problem.url}>Fix</Link>
                            </li>
                        ))}
                    </ul>
                )}

                <PcBuilderParts parts={parts} icons={icons} />

                <details className="admin-pcb-guide">
                    <summary>
                        <Cpu size={16} />
                        <span>How products get into the builder</span>
                    </summary>

                    <div className="admin-pcb-guide-body">
                        <section>
                            <h3>To put a product in a part</h3>
                            <p>
                                On the product, set its{' '}
                                <strong>Category</strong> to one shown in that
                                part&rsquo;s <em>Products from</em> column, or a
                                category under it — a processor, for example,
                                filed under <code>Component › Processor</code>.
                                Make sure <strong>Active</strong> is ticked.
                                Out-of-stock products still appear, marked out
                                of stock, so a customer can plan around
                                something you are restocking.
                            </p>
                        </section>

                        <section>
                            <h3>To make the fit check work</h3>
                            <p>
                                Parts marked <strong>Checked for fit</strong>{' '}
                                are compared using the product&rsquo;s{' '}
                                <strong>Specifications</strong>. The{' '}
                                <em>Specs</em> column says which names each part
                                needs — for example a processor needs{' '}
                                <code>Socket</code> (AM5) and <code>TDP</code>{' '}
                                (120W). The name must match exactly; write
                                wattages with the W. A product missing one is
                                shown to customers as &ldquo;could not
                                confirm&rdquo;, not as a fit.
                            </p>
                            <p>
                                <Link
                                    href={`${ROUTES.ADMIN_PRODUCTS}?needs_specs=1`}
                                >
                                    See every product missing a spec
                                </Link>
                            </p>
                        </section>
                    </div>
                </details>
            </div>
        </AdminLayout>
    );
}
