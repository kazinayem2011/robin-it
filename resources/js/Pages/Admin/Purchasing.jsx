import React, { useState } from 'react';
import { PurchaseTabs } from './Stock/StockTabs';
import { ROUTES } from '@/constants/endpoints';
import ReceiveDeliveryModal from './Components/ReceiveDeliveryModal';
import PurchaseOrderDetailsModal from './Components/PurchaseOrderDetailsModal';
import { unitLabel } from '@/utils/unitLabel';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    ClipboardList,
    PackageCheck,
    Pencil,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import Tabs from '@/Components/Tabs';
import Modal from '@/Components/Modal';
import FormInput from '@/Components/FormInput';
import Select from '@/Components/Select';
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
    openOrders = [],
}) {
    const [writing, setWriting] = useState(false);
    const [receiving, setReceiving] = useState(null);
    const [editing, setEditing] = useState(null);
    const [viewing, setViewing] = useState(null);
    // Receiving goods bought without an order. Opened straight away when
    // another screen sent someone here to receive stock (?receive=1).
    const [receivingFree, setReceivingFree] = useState(() => {
        try {
            return (
                new URLSearchParams(window.location.search).get('receive') ===
                '1'
            );
        } catch {
            return false;
        }
    });

    const go = (params) =>
        router.get(
            '/admin/purchase-orders',
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const refresh = () =>
        router.reload({ only: ['orders', 'counts', 'openOrders'] });

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
            // The number opens everything about the order.
            render: (o) => (
                <div>
                    <button
                        type="button"
                        className="po-open-details"
                        title="See the order and its deliveries"
                        onClick={() => setViewing(o.id)}
                    >
                        {o.reference}
                    </button>
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
                    {/* Open until everything has arrived: it can be
                        changed and received against. What already arrived
                        is protected line by line. */}
                    {OPEN_STATUSES.includes(o.status) && (
                        <>
                            <button
                                type="button"
                                className="admin-table-icon-btn"
                                title="Edit — quantities and prices"
                                aria-label={`Edit ${o.reference}`}
                                onClick={() => setEditing(o)}
                            >
                                <Pencil size={14} />
                            </button>

                            <button
                                type="button"
                                className="admin-table-icon-btn"
                                title="Receive delivery"
                                aria-label={`Receive delivery for ${o.reference}`}
                                onClick={() => setReceiving(o)}
                            >
                                <PackageCheck size={14} />
                            </button>
                        </>
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
            title="Purchases"
            subtitle={
                branch
                    ? `Orders coming into ${branch}`
                    : 'What the shop has asked its suppliers for'
            }
        >
            <Head title="Purchasing" />
            <PurchaseTabs current={ROUTES.ADMIN_PURCHASING} />

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
                subtitle="Click an order number to see its deliveries. An order can be changed until everything has arrived."
                headerActions={
                    <div className="admin-input-row-flex">
                        {/* One way in for every delivery: it asks which
                            order it is for, or none. */}
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={PackageCheck}
                            onClick={() => setReceivingFree(true)}
                        >
                            Receive delivery
                        </Button>
                        <Button
                            variant="primary"
                            size="sm"
                            icon={Plus}
                            onClick={() => setWriting(true)}
                        >
                            New order
                        </Button>
                    </div>
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
                open={writing || Boolean(editing)}
                editing={editing}
                suppliers={suppliers}
                stores={stores}
                onClose={() => {
                    setWriting(false);
                    setEditing(null);
                }}
                onSaved={() => {
                    setWriting(false);
                    setEditing(null);
                    refresh();
                }}
            />

            <PurchaseOrderDetailsModal
                orderId={viewing}
                onClose={() => setViewing(null)}
                onEdit={(o) => {
                    setViewing(null);
                    setEditing(o);
                }}
                onReceive={(o) => {
                    setViewing(null);
                    setReceiving(o);
                }}
            />

            <ReceiveDeliveryModal
                isOpen={receivingFree}
                order={null}
                stores={stores}
                suppliers={suppliers}
                openOrders={openOrders}
                onClose={() => setReceivingFree(false)}
                onSaved={() => {
                    setReceivingFree(false);
                    refresh();
                }}
            />

            <ReceiveDeliveryModal
                isOpen={Boolean(receiving)}
                order={receiving}
                stores={stores}
                onClose={() => setReceiving(null)}
                onSaved={() => {
                    setReceiving(null);
                    refresh();
                }}
            />
        </AdminLayout>
    );
}

/** Orders that can still be changed and received against. */
const OPEN_STATUSES = ['draft', 'sent', 'partial'];

/** Writing an order: a supplier, a date, and the lines. */
/**
 * @param editing An open order to change, or null to raise a new one.
 *
 * The screen offered Send, Receive and Cancel and nothing else, so an order
 * saved with the wrong price — or with none, which the form allows because a
 * price is not always known when the order is raised — could not be put right.
 * The endpoint to do it has existed all along; nothing called it.
 */
function WriteOrderModal({
    open,
    editing = null,
    suppliers,
    stores,
    onClose,
    onSaved,
}) {
    const [supplierId, setSupplierId] = useState('');
    const [storeId, setStoreId] = useState('');
    const [expected, setExpected] = useState('');
    const [note, setNote] = useState('');
    const [lines, setLines] = useState([]);
    const [search, setSearch] = useState('');
    const [units, setUnits] = useState([]);
    const [saving, setSaving] = useState(false);

    /* Loaded on open, so re-opening a draft shows what it holds now rather
       than whatever was last typed into the form. */
    React.useEffect(() => {
        if (!open) return;

        setSearch('');
        setUnits([]);
        setSupplierId(editing ? String(editing.supplier_id ?? '') : '');
        setStoreId(editing?.store_id ? String(editing.store_id) : '');
        setExpected(
            editing?.expected_on ? editing.expected_on.slice(0, 10) : '',
        );
        setNote(editing?.note ?? '');
        setLines(
            (editing?.items ?? []).map((i) => ({
                key: `${i.product_id}:${i.product_variant_id ?? ''}`,
                product_id: i.product_id,
                product_variant_id: i.product_variant_id ?? null,
                name: i.display_name ?? `#${i.product_id}`,
                quantity: i.quantity,
                // Already arrived: the floor for the quantity, and the reason
                // the line cannot be taken off.
                received: i.quantity_received ?? 0,
                unit_cost:
                    i.unit_cost === null || i.unit_cost === undefined
                        ? ''
                        : String(i.unit_cost),
            })),
        );
    }, [open, editing]);

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
                // With the shelf: four products can share a name.
                name: unitLabel(product, variant),
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

    const anyArrived = lines.some((l) => l.received > 0);
    const tooLow = lines.find((l) => Number(l.quantity) < (l.received || 1));

    const total = lines.reduce(
        (sum, l) => sum + Number(l.quantity || 0) * Number(l.unit_cost || 0),
        0,
    );

    const save = async () => {
        setSaving(true);

        try {
            const payload = {
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
            };

            const res = editing
                ? await adminService.updatePurchaseOrder(editing.id, payload)
                : await adminService.createPurchaseOrder(payload);

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
            title={editing ? `Edit ${editing.reference}` : 'New purchase order'}
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
                        disabled={
                            !supplierId || lines.length === 0 || Boolean(tooLow)
                        }
                    >
                        Save order
                    </Button>
                </>
            }
        >
            <div className="po-header-grid">
                <Select
                    label="Supplier"
                    name="po_supplier"
                    required
                    // Deliveries came from this supplier; they stay theirs.
                    disabled={anyArrived}
                    helperText={
                        anyArrived
                            ? 'Part of this order has arrived, so the supplier stays.'
                            : undefined
                    }
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
                    <Select
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
                            {editing && <th className="po-num">Arrived</th>}
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
                                        min={Math.max(1, l.received || 0)}
                                        aria-label={`How many ${l.name}`}
                                        aria-invalid={
                                            Number(l.quantity) <
                                            (l.received || 1)
                                        }
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
                                {editing && (
                                    <td className="po-num">
                                        {l.received || '—'}
                                    </td>
                                )}
                                <td className="po-num">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        aria-label={`Cost of each ${l.name}`}
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
                                    {l.received > 0 ? null : (
                                        <button
                                            type="button"
                                            className="admin-table-icon-btn"
                                            title="Take off the order"
                                            aria-label={`Take ${l.name} off the order`}
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
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colSpan={editing ? 3 : 2}>
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

            {tooLow && (
                <p className="auth-field-error" role="alert">
                    {tooLow.received > 0
                        ? `${tooLow.name}: ${tooLow.received} have already arrived, so the quantity cannot go below ${tooLow.received}.`
                        : `${tooLow.name}: enter at least 1.`}
                </p>
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
