import React, { useCallback, useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Hash,
    ShieldCheck,
    ShieldOff,
    Plus,
    Pencil,
    Trash2,
} from 'lucide-react';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import Tabs from '@/Components/Tabs';
import Modal from '@/Components/Modal';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { ROUTES } from '@/constants/endpoints';
import './Count.css';

/**
 * Every unit the shop tracks by serial, and where it is.
 *
 * The question this answers is the one asked at the counter when somebody
 * walks in with a dead card: is this ours, who bought it, and is it covered.
 *
 * It is also where a serial gets fixed. Numbers used to arrive only with a
 * delivery and could never be changed, so a shop that started tracking them
 * part way through had no way to write down the shelf it already had, and a
 * number typed wrong stayed wrong for the life of the unit.
 */
export default function StockSerials({
    serials = { data: [] },
    filters = {},
    statuses = {},
    stores = [],
    branch = null,
    counts = {},
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(null);

    const go = (params) =>
        router.get(
            ROUTES.ADMIN_STOCK_SERIALS,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const refresh = () => router.reload({ only: ['serials', 'counts'] });

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
            key: 'serial',
            header: 'Serial',
            render: (s) => <code className="serial-code">{s.serial}</code>,
        },
        {
            key: 'product',
            header: 'Product',
            render: (s) => (
                <div className="admin-stock-product-name">{s.product}</div>
            ),
        },
        {
            key: 'status',
            header: 'Where it is',
            render: (s) => (
                <div>
                    <div>{s.status_label}</div>
                    <div className="admin-field-hint">
                        {s.order_number
                            ? `Order ${s.order_number}`
                            : (s.store ?? '—')}
                    </div>
                </div>
            ),
        },
        {
            key: 'sold_at',
            header: 'Sold',
            render: (s) =>
                s.sold_at ?? <span className="admin-field-hint">—</span>,
        },
        {
            key: 'warranty',
            header: 'Warranty',
            render: (s) => {
                // Null, not false, for a unit still on the shelf: it has no
                // cover to be inside or outside of yet.
                if (s.under_warranty === null) {
                    return <span className="admin-field-hint">—</span>;
                }

                return s.under_warranty ? (
                    <span className="serial-covered">
                        <ShieldCheck size={13} /> until {s.warranty_until}
                    </span>
                ) : (
                    <span className="serial-expired">
                        <ShieldOff size={13} /> ended {s.warranty_until}
                    </span>
                );
            },
        },
        {
            key: 'actions',
            header: '',
            render: (s) => (
                <div className="serial-row-actions">
                    <button
                        type="button"
                        title="Correct this number"
                        onClick={() => setEditing(s)}
                    >
                        <Pencil size={15} />
                    </button>

                    {/*
                     * Only for a unit that never reached a customer. A sold
                     * unit's serial is part of that sale and of any warranty
                     * resting on it, so the way to change one of those is to
                     * correct it, which keeps the history.
                     */}
                    {!s.order_number && (
                        <button
                            type="button"
                            title="Remove — recorded in error"
                            className="is-danger"
                            onClick={() => remove(s)}
                        >
                            <Trash2 size={15} />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    const remove = async (serial) => {
        if (
            !window.confirm(
                `Remove serial ${serial.serial}? Use this only when the number was recorded in error.`,
            )
        ) {
            return;
        }

        try {
            const res = await adminService.removeSerial(serial.id);
            toast.success(res?.message || 'Serial removed.');
            refresh();
        } catch (err) {
            toast.error(err?.message || 'Could not remove that serial.');
        }
    };

    return (
        <AdminLayout
            title="Serial numbers"
            subtitle={
                branch
                    ? `Units tracked at ${branch}`
                    : 'Which unit went to which customer'
            }
        >
            <Head title="Serial numbers" />

            <Tabs
                variant="enclosed"
                tabs={tabs}
                activeTab={filters.status || ''}
                onChange={(status) => go({ status: status || undefined })}
            />

            <DataTable
                columns={columns}
                data={serials.data ?? []}
                searchable
                searchValue={filters.q || ''}
                onSearch={(q) => go({ q: q || undefined })}
                searchPlaceholder="Serial, product or order number…"
                title="Tracked units"
                subtitle="Recorded when a delivery is received, and tied to an order when it ships"
                headerActions={
                    <Button
                        variant="primary"
                        size="sm"
                        icon={Plus}
                        onClick={() => setAdding(true)}
                    >
                        Add serials
                    </Button>
                }
                emptyTitle="No serials recorded"
                emptyDescription="Serial numbers are entered against a line when you receive a delivery. For stock already on the shelf, use Add serials."
                emptyIcon={Hash}
                pagination={false}
            />

            {serials.last_page > 1 && (
                <Pagination
                    links={serials.links}
                    currentPage={serials.current_page}
                    totalPages={serials.last_page}
                    from={serials.from}
                    to={serials.to}
                    total={serials.total}
                />
            )}

            <AddSerialsModal
                open={adding}
                stores={stores}
                onClose={() => setAdding(false)}
                onSaved={() => {
                    setAdding(false);
                    refresh();
                }}
            />

            <CorrectSerialModal
                serial={editing}
                onClose={() => setEditing(null)}
                onSaved={() => {
                    setEditing(null);
                    refresh();
                }}
            />
        </AdminLayout>
    );
}

/**
 * Recording serials against stock that is already on the shelf.
 *
 * The count is checked on the server against what the branch holds — more
 * serials than units means the serial list and the stock figure disagree, and
 * once they disagree neither settles a warranty argument.
 */
function AddSerialsModal({ open, stores, onClose, onSaved }) {
    const [units, setUnits] = useState([]);
    const [search, setSearch] = useState('');
    const [unit, setUnit] = useState('');
    const [storeId, setStoreId] = useState(stores[0]?.id ?? '');
    const [text, setText] = useState('');
    const [saving, setSaving] = useState(false);

    const load = useCallback(async (term) => {
        try {
            const res = await adminService.getStockUnits({ search: term });
            setUnits(res?.data ?? res ?? []);
        } catch {
            setUnits([]);
        }
    }, []);

    useEffect(() => {
        if (!open) return undefined;

        // Debounced, or every keystroke is a request.
        const t = setTimeout(() => load(search), 250);
        return () => clearTimeout(t);
    }, [open, search, load]);

    /*
     * One per line, and blank lines dropped.
     *
     * People paste a column out of a supplier's spreadsheet, which brings
     * trailing blanks and stray spaces with it. Commas are treated as
     * separators too, because the other half paste a single row.
     */
    const parsed = text
        .split(/[\n,]/)
        .map((s) => s.trim())
        .filter(Boolean);

    const options = units.flatMap((p) =>
        p.has_variants && p.variants?.length
            ? p.variants
                  .filter((v) => v.is_active)
                  .map((v) => ({
                      value: `${p.id}:${v.id}`,
                      label: `${p.name} (${v.name}) — ${v.stock_quantity} in stock`,
                  }))
            : [
                  {
                      value: `${p.id}:`,
                      label: `${p.name} — ${p.stock_quantity} in stock`,
                  },
              ],
    );

    const submit = async () => {
        const [productId, variantId] = unit.split(':');

        setSaving(true);

        try {
            const res = await adminService.addSerials({
                product_id: Number(productId),
                product_variant_id: variantId ? Number(variantId) : null,
                store_id: storeId || null,
                serials: parsed,
            });
            toast.success(res?.message || 'Serials recorded.');
            setText('');
            setUnit('');
            onSaved();
        } catch (err) {
            toast.error(err?.message || 'Could not record those serials.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={open}
            onClose={onClose}
            title="Add serials to stock on the shelf"
            maxWidth="560px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={submit}
                        loading={saving}
                        disabled={!unit || parsed.length === 0}
                    >
                        Record {parsed.length || ''}{' '}
                        {parsed.length === 1 ? 'serial' : 'serials'}
                    </Button>
                </>
            }
        >
            <p className="admin-field-hint" style={{ marginBottom: 16 }}>
                For stock received before you started recording serials, or a
                delivery taken in with the boxes still sealed. Units bought from
                now on are best serialised as they arrive.
            </p>

            <FormInput
                label="Find a product"
                name="serial_search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Type part of the name…"
            />

            <FormSelect
                label="Product"
                name="serial_unit"
                required
                value={unit}
                onChange={(e) => setUnit(e.target.value)}
                options={[
                    { value: '', label: 'Choose a product…' },
                    ...options,
                ]}
            />

            {stores.length > 1 && (
                <FormSelect
                    label="Branch"
                    name="serial_store"
                    required
                    value={storeId}
                    onChange={(e) => setStoreId(e.target.value)}
                    options={stores.map((s) => ({
                        value: s.id,
                        label: s.name,
                    }))}
                />
            )}

            <FormInput
                label="Serial numbers"
                name="serial_list"
                type="textarea"
                rows={7}
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder={'One per line\nSN-0001\nSN-0002'}
            />

            <p className="admin-field-hint">
                {parsed.length} entered. Anything already on the books is
                skipped and named back to you rather than duplicated.
            </p>
        </Modal>
    );
}

/**
 * Fixing a number that was typed wrong.
 *
 * Allowed on a sold unit on purpose — that is when the mistake surfaces, at the
 * counter, with the customer holding a machine whose sticker does not match the
 * invoice. The sale, the dates and the cover are untouched; only the label
 * changes, and the old number is kept in the note.
 */
function CorrectSerialModal({ serial, onClose, onSaved }) {
    const [value, setValue] = useState('');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setValue(serial?.serial ?? '');
        setReason('');
    }, [serial]);

    const submit = async () => {
        setSaving(true);

        try {
            const res = await adminService.correctSerial(serial.id, {
                serial: value,
                reason: reason || null,
            });
            toast.success(res?.message || 'Serial corrected.');
            onSaved();
        } catch (err) {
            toast.error(err?.message || 'Could not correct that serial.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={Boolean(serial)}
            onClose={onClose}
            title="Correct a serial number"
            maxWidth="480px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={submit}
                        loading={saving}
                        disabled={!value.trim() || value === serial?.serial}
                    >
                        Save correction
                    </Button>
                </>
            }
        >
            <p className="admin-field-hint" style={{ marginBottom: 16 }}>
                {serial?.product}
                {serial?.order_number
                    ? ` · sold on order ${serial.order_number}. The sale and its warranty are untouched.`
                    : ' · on the shelf.'}
            </p>

            <FormInput
                label="Serial number"
                name="corrected_serial"
                required
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder="As it reads on the unit"
            />

            <FormInput
                label="Why (optional)"
                name="correction_reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Read from the sticker at the counter"
            />

            <p className="admin-field-hint">
                The old number is kept in this unit's notes, so a claim argued
                later can be shown what changed.
            </p>
        </Modal>
    );
}
