import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    ClipboardList,
    Plus,
    Send,
    PackageCheck,
    XCircle,
    Trash2,
} from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import Tabs from '@/Components/Tabs';
import Modal from '@/Components/Modal';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';
import './Purchasing.css';

/**
 * What the shop has asked its suppliers for.
 *
 * Stock receipts record what arrived. Nothing recorded what was asked for, so
 * between placing an order and its arrival the shop had no record of it at
 * all: no answer to "when are those back in", no way to tell a supplier who
 * shipped fifteen of twenty that they still owe five, and nothing to check an
 * invoice against.
 */
export default function Purchasing({
    orders = { data: [] },
    filters = {},
    statuses = {},
    suppliers = [],
    stores = [],
    branch = null,
    counts = {},
}) {
    const [writing, setWriting] = useState(false);
    const [receiving, setReceiving] = useState(null);

    const go = (params) =>
        router.get(
            '/admin/purchase-orders',
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const refresh = () => router.reload({ only: ['orders', 'counts'] });

    const act = async (fn, order) => {
        try {
            const res = await fn(order.id);
            toast.success(res?.message || 'Done.');
            refresh();
        } catch (err) {
            toast.error(err?.message || 'That did not work.');
        }
    };

    const tabs = [
        { key: '', label: 'All' },
        ...Object.entries(statuses).map(([key, label]) => ({
            key,
            label,
            badge: counts[key] ?? 0,
        })),
    ];

    const columns = [
        {
            key: 'reference',
            header: 'Order',
            render: (o) => (
                <div>
                    <strong className="admin-table-item-title">
                        {o.reference}
                    </strong>
                    <div className="admin-field-hint">{o.supplier_name}</div>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Where it is',
            render: (o) => (
                <div>
                    <span className={`po-badge po-${o.status}`}>
                        {o.status_label}
                    </span>
                    {o.expected_on && (
                        <div className="admin-field-hint">
                            expected {String(o.expected_on).slice(0, 10)}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'quantity',
            header: 'Ordered',
            render: (o) => `${o.total_quantity} units`,
        },
        {
            /*
             * The number this whole screen exists for: what the supplier still
             * owes. Everything else here is bookkeeping around it.
             */
            key: 'outstanding',
            header: 'Still owed',
            render: (o) =>
                o.outstanding > 0 ? (
                    <strong className="po-outstanding">{o.outstanding}</strong>
                ) : (
                    <span className="admin-field-hint">—</span>
                ),
        },
        {
            key: 'cost',
            header: 'Value',
            render: (o) => formatBdt(o.total_cost),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (o) => (
                <div className="admin-input-row-flex">
                    {o.status === 'draft' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Send to the supplier"
                            onClick={() =>
                                act(adminService.sendPurchaseOrder, o)
                            }
                        >
                            <Send size={14} />
                        </button>
                    )}

                    {['sent', 'partial'].includes(o.status) && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Book in a delivery"
                            onClick={() => setReceiving(o)}
                        >
                            <PackageCheck size={14} />
                        </button>
                    )}

                    {o.status !== 'received' && o.status !== 'cancelled' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Cancel — it is not coming"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        `Cancel ${o.reference}? Anything already received stays received.`,
                                    )
                                ) {
                                    act(adminService.cancelPurchaseOrder, o);
                                }
                            }}
                        >
                            <XCircle size={14} />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Purchasing"
            subtitle={
                branch
                    ? `Orders coming into ${branch}`
                    : 'What the shop has asked its suppliers for'
            }
        >
            <Head title="Purchasing" />

            <Tabs
                variant="enclosed"
                tabs={tabs}
                activeTab={filters.status || ''}
                onChange={(status) => go({ status: status || undefined })}
            />

            <DataTable
                columns={columns}
                data={orders.data ?? []}
                title="Purchase orders"
                subtitle="A draft can be changed; once it is with the supplier it can only be received against or cancelled"
                headerActions={
                    <Button
                        variant="primary"
                        size="sm"
                        icon={Plus}
                        onClick={() => setWriting(true)}
                    >
                        New order
                    </Button>
                }
                emptyTitle="Nothing on order"
                emptyDescription="Write an order when you ask a supplier for stock, and what arrives can be checked against it."
                emptyIcon={ClipboardList}
                pagination={false}
            />

            {orders.last_page > 1 && (
                <Pagination
                    links={orders.links}
                    currentPage={orders.current_page}
                    totalPages={orders.last_page}
                    from={orders.from}
                    to={orders.to}
                    total={orders.total}
                />
            )}

            <WriteOrderModal
                open={writing}
                suppliers={suppliers}
                stores={stores}
                onClose={() => setWriting(false)}
                onSaved={() => {
                    setWriting(false);
                    refresh();
                }}
            />

            <ReceiveModal
                order={receiving}
                onClose={() => setReceiving(null)}
                onSaved={() => {
                    setReceiving(null);
                    refresh();
                }}
            />
        </AdminLayout>
    );
}

/** Writing an order: a supplier, a date, and the lines. */
function WriteOrderModal({ open, suppliers, stores, onClose, onSaved }) {
    const [supplierId, setSupplierId] = useState('');
    const [storeId, setStoreId] = useState('');
    const [expected, setExpected] = useState('');
    const [note, setNote] = useState('');
    const [lines, setLines] = useState([]);
    const [search, setSearch] = useState('');
    const [units, setUnits] = useState([]);
    const [saving, setSaving] = useState(false);

    const find = async (term) => {
        setSearch(term);

        if (term.trim().length < 2) {
            setUnits([]);
            return;
        }

        try {
            const res = await adminService.getStockUnits({ search: term });
            setUnits(res?.data ?? res ?? []);
        } catch {
            setUnits([]);
        }
    };

    const add = (product, variant = null) => {
        const key = `${product.id}:${variant?.id ?? ''}`;

        if (lines.some((l) => l.key === key)) return;

        setLines((prev) => [
            ...prev,
            {
                key,
                product_id: product.id,
                product_variant_id: variant?.id ?? null,
                name: variant
                    ? `${product.name} (${variant.name})`
                    : product.name,
                quantity: 1,
                unit_cost: '',
            },
        ]);
        setSearch('');
        setUnits([]);
    };

    const setLine = (key, field, value) =>
        setLines((prev) =>
            prev.map((l) => (l.key === key ? { ...l, [field]: value } : l)),
        );

    const total = lines.reduce(
        (sum, l) => sum + Number(l.quantity || 0) * Number(l.unit_cost || 0),
        0,
    );

    const save = async () => {
        setSaving(true);

        try {
            const res = await adminService.createPurchaseOrder({
                supplier_id: Number(supplierId),
                store_id: storeId ? Number(storeId) : null,
                expected_on: expected || null,
                note: note || null,
                lines: lines.map((l) => ({
                    product_id: l.product_id,
                    product_variant_id: l.product_variant_id,
                    quantity: Number(l.quantity),
                    unit_cost: l.unit_cost === '' ? null : Number(l.unit_cost),
                })),
            });
            toast.success(res?.message || 'Order saved.');
            setLines([]);
            setSupplierId('');
            setExpected('');
            setNote('');
            onSaved();
        } catch (err) {
            toast.error(err?.message || 'Could not save that order.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={open}
            onClose={onClose}
            title="New purchase order"
            maxWidth="720px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={save}
                        loading={saving}
                        disabled={!supplierId || lines.length === 0}
                    >
                        Save as draft
                    </Button>
                </>
            }
        >
            <div className="po-header-grid">
                <FormSelect
                    label="Supplier"
                    name="po_supplier"
                    required
                    value={supplierId}
                    onChange={(e) => setSupplierId(e.target.value)}
                    options={[
                        { value: '', label: 'Choose a supplier…' },
                        ...suppliers.map((s) => ({
                            value: s.id,
                            label: s.name,
                        })),
                    ]}
                />

                <FormInput
                    label="Expected on"
                    name="po_expected"
                    type="date"
                    value={expected}
                    onChange={(e) => setExpected(e.target.value)}
                />

                {stores.length > 1 && (
                    <FormSelect
                        label="Coming into"
                        name="po_store"
                        value={storeId}
                        onChange={(e) => setStoreId(e.target.value)}
                        options={[
                            { value: '', label: 'Decide on arrival' },
                            ...stores.map((s) => ({
                                value: s.id,
                                label: s.name,
                            })),
                        ]}
                    />
                )}
            </div>

            <FormInput
                label="Add a product"
                name="po_search"
                value={search}
                onChange={(e) => find(e.target.value)}
                placeholder="Type part of the name…"
            />

            {units.length > 0 && (
                <div className="po-results">
                    {units.slice(0, 8).map((p) =>
                        p.has_variants && p.variants?.length ? (
                            p.variants
                                .filter((v) => v.is_active)
                                .map((v) => (
                                    <button
                                        key={`${p.id}:${v.id}`}
                                        type="button"
                                        onClick={() => add(p, v)}
                                    >
                                        {p.name} ({v.name})
                                    </button>
                                ))
                        ) : (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => add(p)}
                            >
                                {p.name}
                            </button>
                        ),
                    )}
                </div>
            )}

            {lines.length > 0 && (
                <table className="po-lines">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th className="po-num">Quantity</th>
                            <th className="po-num">Unit cost</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.key}>
                                <td>{l.name}</td>
                                <td className="po-num">
                                    <input
                                        type="number"
                                        min="1"
                                        value={l.quantity}
                                        onChange={(e) =>
                                            setLine(
                                                l.key,
                                                'quantity',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </td>
                                <td className="po-num">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={l.unit_cost}
                                        placeholder="What they quoted"
                                        onChange={(e) =>
                                            setLine(
                                                l.key,
                                                'unit_cost',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        className="admin-table-icon-btn"
                                        onClick={() =>
                                            setLines((prev) =>
                                                prev.filter(
                                                    (x) => x.key !== l.key,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 size={13} />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colSpan={2}>
                                {lines.length} line
                                {lines.length === 1 ? '' : 's'}
                            </td>
                            <td className="po-num" colSpan={2}>
                                <strong>{formatBdt(total)}</strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            )}

            <FormInput
                label="Note"
                name="po_note"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Anything the supplier or your storekeeper should know"
            />
        </Modal>
    );
}

/**
 * Booking in what actually turned up.
 *
 * Pre-filled with what is still outstanding, because most deliveries are
 * complete — but every line is editable, because the ones that are not are
 * exactly what this record exists to catch.
 */
function ReceiveModal({ order, onClose, onSaved }) {
    const [got, setGot] = useState({});
    const [invoice, setInvoice] = useState('');
    const [saving, setSaving] = useState(false);

    React.useEffect(() => {
        if (!order) return;

        setGot(
            Object.fromEntries(
                (order.items ?? []).map((i) => [
                    i.id,
                    String(Math.max(0, i.quantity - i.quantity_received)),
                ]),
            ),
        );
        setInvoice('');
    }, [order]);

    const save = async () => {
        setSaving(true);

        try {
            const res = await adminService.receivePurchaseOrder(order.id, {
                invoice_number: invoice || null,
                lines: (order.items ?? [])
                    .map((i) => ({
                        purchase_order_item_id: i.id,
                        quantity: Number(got[i.id] ?? 0),
                    }))
                    .filter((l) => l.quantity > 0),
            });
            toast.success(res?.message || 'Received.');
            onSaved();
        } catch (err) {
            toast.error(err?.message || 'Could not book that delivery in.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(order)}
            onClose={onClose}
            title={`Receive against ${order?.reference ?? ''}`}
            maxWidth="600px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={save} loading={saving}>
                        Book it in
                    </Button>
                </>
            }
        >
            <p className="admin-field-hint" style={{ marginBottom: 16 }}>
                Enter what actually arrived. Anything short stays outstanding on
                the order, so you can see what {order?.supplier_name} still
                owes.
            </p>

            <FormInput
                label="Their invoice number"
                name="po_invoice"
                value={invoice}
                onChange={(e) => setInvoice(e.target.value)}
                placeholder="Optional, but worth having"
            />

            <table className="po-lines">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th className="po-num">Outstanding</th>
                        <th className="po-num">Arrived</th>
                    </tr>
                </thead>
                <tbody>
                    {(order?.items ?? []).map((i) => {
                        const left = Math.max(
                            0,
                            i.quantity - i.quantity_received,
                        );

                        return (
                            <tr key={i.id}>
                                <td>{i.display_name ?? `#${i.product_id}`}</td>
                                <td className="po-num">{left}</td>
                                <td className="po-num">
                                    <input
                                        type="number"
                                        min="0"
                                        max={left}
                                        value={got[i.id] ?? ''}
                                        onChange={(e) =>
                                            setGot((prev) => ({
                                                ...prev,
                                                [i.id]: e.target.value,
                                            }))
                                        }
                                    />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </Modal>
    );
}
