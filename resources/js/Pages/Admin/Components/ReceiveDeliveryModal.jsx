import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, Split, Hash } from 'lucide-react';
import Modal from '@/Components/Modal';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import Select from '@/Components/Select';
import SearchableSelect from '@/Components/SearchableSelect';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { listFrom } from '@/utils/apiPayload';
import { unitLabel } from '@/utils/unitLabel';
import { formatBdt } from '@/utils/formatters';

/**
 * Receiving a delivery: the one screen for it.
 *
 * There were two — "Book in a delivery" on the stock page and "Receive" on a
 * purchase order — that looked unrelated, and neither could send part of a
 * delivery to another branch. This is both: opened from a purchase order it
 * lists what was ordered; opened on its own the products are picked here.
 *
 * Written for whoever is at the door with the boxes. Everything goes to one
 * branch unless a line is split; the split must add up to what arrived, and
 * it says so as it is typed.
 */

let nextKey = 1;

/** Today, as the date box wants it (YYYY-MM-DD), in the shop's own time. */
const today = () => {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const newKey = () => `line-${nextKey++}`;

const countSerials = (text) =>
    String(text || '')
        .split(/[\r\n,]+/)
        .map((s) => s.trim())
        .filter(Boolean).length;

const primaryOf = (stores) =>
    String(stores.find((s) => s.fulfils_online)?.id ?? stores[0]?.id ?? '');

/**
 * @param {object|null} order     a purchase order to receive against, or null
 * @param {Array}       stores    the branches this person may receive into
 * @param {Array}       suppliers for a delivery without an order
 */
export default function ReceiveDeliveryModal({
    isOpen,
    order = null,
    stores = [],
    suppliers = [],
    onClose,
    onSaved,
}) {
    const fromOrder = Boolean(order);

    const [branch, setBranch] = useState(primaryOf(stores));
    const [supplierId, setSupplierId] = useState('');
    const [invoice, setInvoice] = useState('');
    // The day it came in — today unless it is being entered late.
    const [receivedOn, setReceivedOn] = useState(today());
    const [note, setNote] = useState('');
    const [lines, setLines] = useState([]);
    const [units, setUnits] = useState([]);
    const [saving, setSaving] = useState(false);
    const [tried, setTried] = useState(false);

    // A fresh screen each time it opens.
    useEffect(() => {
        if (!isOpen) return;

        setBranch(String(order?.store_id ?? primaryOf(stores)));
        setSupplierId('');
        setInvoice('');
        setReceivedOn(today());
        setNote('');
        setTried(false);
        setLines(
            fromOrder
                ? (order.items ?? [])
                      .map((i) => ({
                          key: newKey(),
                          itemId: i.id,
                          name: i.display_name ?? `#${i.product_id}`,
                          ordered: i.quantity,
                          received: i.quantity_received,
                          arrived: String(
                              Math.max(0, i.quantity - i.quantity_received),
                          ),
                          cost:
                              i.unit_cost === null || i.unit_cost === undefined
                                  ? ''
                                  : String(i.unit_cost),
                          split: null,
                          serials: '',
                          showSerials: false,
                      }))
                      .filter((l) => l.ordered > l.received)
                : [blankLine()],
        );
        // Opening is the trigger; the order and branches come with it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, order?.id]);

    const loadUnits = useCallback(async (term = '') => {
        try {
            setUnits(
                listFrom(
                    await adminService.getStockUnits(
                        term ? { search: term } : {},
                    ),
                ),
            );
        } catch {
            setUnits([]);
        }
    }, []);

    useEffect(() => {
        if (isOpen && !fromOrder) loadUnits();
    }, [isOpen, fromOrder, loadUnits]);

    const productOptions = useMemo(
        () =>
            units.flatMap((p) =>
                p.has_variants
                    ? (p.variants || [])
                          .filter((v) => v.is_active)
                          .map((v) => ({
                              value: `${p.id}:${v.id}`,
                              label: unitLabel(p, v),
                          }))
                    : [{ value: `${p.id}:`, label: unitLabel(p) }],
            ),
        [units],
    );

    const setLine = (key, patch) =>
        setLines((all) =>
            all.map((l) => (l.key === key ? { ...l, ...patch } : l)),
        );

    const nameOf = (id) =>
        stores.find((s) => String(s.id) === String(id))?.name;

    // --- The checks, in words a person at the door understands -------------
    const problems = lines.flatMap((l) => {
        const arrived = Number(l.arrived) || 0;
        if (arrived <= 0) return [];

        const out = [];
        const label = fromOrder ? l.name : l.label || 'A product';

        if (!fromOrder && !l.unit)
            out.push('Choose the product on every line.');
        if (fromOrder && arrived > l.ordered - l.received) {
            out.push(
                `${label}: only ${l.ordered - l.received} still to come on this order.`,
            );
        }
        if (fromOrder && l.cost === '') {
            out.push(`${label}: enter what each one cost.`);
        }
        if (l.split) {
            const placed = Object.values(l.split).reduce(
                (sum, n) => sum + (Number(n) || 0),
                0,
            );
            if (placed !== arrived) {
                out.push(
                    `${label}: ${arrived} arrived but ${placed} placed in branches.`,
                );
            }
        }
        return out;
    });

    const counted = lines.filter((l) => (Number(l.arrived) || 0) > 0);
    const totals = counted.reduce(
        (t, l) => ({
            qty: t.qty + Number(l.arrived),
            cost: t.cost + Number(l.arrived) * (Number(l.cost) || 0),
        }),
        { qty: 0, cost: 0 },
    );

    const save = async () => {
        setTried(true);

        if (counted.length === 0) {
            toast.error('Enter how many arrived.');
            return;
        }
        if (problems.length) {
            toast.error(problems[0]);
            return;
        }

        const splitOf = (l) =>
            l.split
                ? Object.fromEntries(
                      Object.entries(l.split)
                          .map(([id, n]) => [id, Number(n) || 0])
                          .filter(([, n]) => n > 0),
                  )
                : null;

        setSaving(true);
        try {
            let res;
            if (fromOrder) {
                res = await adminService.receivePurchaseOrder(order.id, {
                    store_id: branch ? Number(branch) : null,
                    invoice_number: invoice || null,
                    received_on: receivedOn || null,
                    note: note.trim() || null,
                    lines: counted.map((l) => ({
                        purchase_order_item_id: l.itemId,
                        quantity: Number(l.arrived),
                        unit_cost: l.cost === '' ? null : Number(l.cost),
                        branches: splitOf(l),
                        serials: l.serials.trim() || null,
                    })),
                });
            } else {
                res = await adminService.receiveStock({
                    supplier_id: supplierId || null,
                    store_id: branch ? Number(branch) : null,
                    invoice_number: invoice || null,
                    received_on: receivedOn || null,
                    note: note.trim() || null,
                    lines: counted.map((l) => {
                        const [productId, variantId] = l.unit.split(':');
                        return {
                            product_id: Number(productId),
                            product_variant_id: variantId
                                ? Number(variantId)
                                : null,
                            quantity: Number(l.arrived),
                            unit_cost: l.cost === '' ? null : Number(l.cost),
                            branches: splitOf(l),
                            serials: l.serials.trim() || null,
                        };
                    }),
                });
            }
            toast.success(
                res?.message || `Received ${totals.qty}.`,
                'Delivery saved',
            );
            onSaved?.();
        } catch (err) {
            toast.error(err?.message || 'Could not save this delivery.');
        } finally {
            setSaving(false);
        }
    };

    const title = fromOrder
        ? `Receive delivery — ${order.reference}`
        : 'Receive delivery';

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={title}
            maxWidth="860px"
            footer={
                <div className="admin-receive-footer">
                    <div className="admin-receive-total">
                        <strong>{totals.qty}</strong>{' '}
                        {totals.qty === 1 ? 'item' : 'items'}
                        {totals.cost > 0 && (
                            <span> · {formatBdt(totals.cost)}</span>
                        )}
                    </div>
                    <div className="admin-input-row-flex">
                        <Button variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={save} loading={saving}>
                            Save delivery
                        </Button>
                    </div>
                </div>
            }
        >
            <p className="admin-field-hint admin-receive-intro">
                {fromOrder
                    ? `From ${order.supplier_name ?? 'the supplier'}. Enter what actually arrived; anything short stays on the order as still to come.`
                    : 'For goods that came without a purchase order. If you made an order for them, receive them from that order in Purchases instead.'}
            </p>

            <div className="admin-grid-3col">
                <Select
                    label="Put everything in"
                    value={branch}
                    onChange={(e) => setBranch(e.target.value)}
                    options={stores.map((s) => ({
                        value: String(s.id),
                        label: s.fulfils_online
                            ? `${s.name} (primary)`
                            : s.name,
                    }))}
                    helperText="To send some to another branch, press Split on that product."
                />

                {!fromOrder && (
                    <Select
                        label="Supplier"
                        value={supplierId}
                        onChange={(e) => setSupplierId(e.target.value)}
                        placeholder="Choose a supplier…"
                        options={suppliers.map((s) => ({
                            value: String(s.id),
                            label:
                                s.kind === 'opening'
                                    ? `${s.name} — stock you already had`
                                    : s.name,
                        }))}
                    />
                )}

                <FormInput
                    id="receive-invoice"
                    label="Invoice number (optional)"
                    value={invoice}
                    onChange={(e) => setInvoice(e.target.value)}
                    placeholder="From the supplier's invoice"
                />

                <FormInput
                    id="receive-received-on"
                    label="Received on"
                    type="date"
                    value={receivedOn}
                    max={today()}
                    onChange={(e) => setReceivedOn(e.target.value)}
                    helperText="Change it if you are entering a delivery late."
                />
            </div>

            <FormInput
                id="receive-note"
                label="Note (optional)"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Anything worth remembering — a damaged box, who signed for it"
            />

            <div className="admin-receive-lines">
                {lines.map((line) => (
                    <ReceiveLine
                        key={line.key}
                        line={line}
                        fromOrder={fromOrder}
                        stores={stores}
                        branch={branch}
                        nameOf={nameOf}
                        productOptions={productOptions}
                        onSearch={loadUnits}
                        canRemove={!fromOrder && lines.length > 1}
                        onChange={(patch) => setLine(line.key, patch)}
                        onRemove={() =>
                            setLines((all) =>
                                all.filter((l) => l.key !== line.key),
                            )
                        }
                    />
                ))}
            </div>

            {!fromOrder && (
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Plus}
                    onClick={() => setLines((all) => [...all, blankLine()])}
                >
                    Add another product
                </Button>
            )}

            {tried && problems.length > 0 && (
                <ul className="admin-receive-problems" role="alert">
                    {problems.map((p) => (
                        <li key={p}>{p}</li>
                    ))}
                </ul>
            )}
        </Modal>
    );
}

function blankLine() {
    return {
        key: newKey(),
        unit: '',
        label: '',
        arrived: '',
        cost: '',
        split: null,
        serials: '',
        showSerials: false,
    };
}

/** One product: how many arrived, what each cost, and where they go. */
function ReceiveLine({
    line,
    fromOrder,
    stores,
    branch,
    nameOf,
    productOptions,
    onSearch,
    canRemove,
    onChange,
    onRemove,
}) {
    const arrived = Number(line.arrived) || 0;
    const placed = line.split
        ? Object.values(line.split).reduce((s, n) => s + (Number(n) || 0), 0)
        : 0;
    const serialCount = countSerials(line.serials);

    const openSplit = () =>
        onChange({
            split: line.split
                ? null
                : Object.fromEntries(
                      stores.map((s) => [
                          String(s.id),
                          String(s.id) === String(branch) ? line.arrived : '',
                      ]),
                  ),
        });

    return (
        <div className="admin-receive-line">
            <div className="admin-receive-line-product">
                {fromOrder ? (
                    <>
                        <strong>{line.name}</strong>
                        <span className="admin-field-hint">
                            Ordered {line.ordered}
                            {line.received > 0 &&
                                ` · already received ${line.received}`}
                        </span>
                    </>
                ) : (
                    <SearchableSelect
                        label="Product"
                        value={line.unit}
                        onSearch={onSearch}
                        onChange={(e) =>
                            onChange({
                                unit: e.target.value,
                                label:
                                    productOptions.find(
                                        (o) => o.value === e.target.value,
                                    )?.label ?? '',
                            })
                        }
                        placeholder="Choose a product…"
                        searchPlaceholder="Type a product name…"
                        options={productOptions}
                    />
                )}
            </div>

            <FormInput
                id={`receive-arrived-${line.key}`}
                label="Arrived"
                type="number"
                min="0"
                value={line.arrived}
                onChange={(e) => onChange({ arrived: e.target.value })}
                aria-label={`How many ${line.name || 'of this'} arrived`}
            />

            <FormInput
                id={`receive-cost-${line.key}`}
                label={fromOrder ? 'Cost each (৳)' : 'Cost each (৳, optional)'}
                type="number"
                min="0"
                step="0.01"
                value={line.cost}
                onChange={(e) => onChange({ cost: e.target.value })}
            />

            <div className="admin-receive-line-actions">
                {stores.length > 1 && (
                    <button
                        type="button"
                        className={`admin-receive-chip${line.split ? ' is-on' : ''}`}
                        onClick={openSplit}
                        title="Send some of these to another branch"
                    >
                        <Split size={13} /> Split
                    </button>
                )}
                <button
                    type="button"
                    className={`admin-receive-chip${serialCount ? ' is-on' : ''}`}
                    onClick={() => onChange({ showSerials: !line.showSerials })}
                    title="Serial numbers (optional)"
                >
                    <Hash size={13} /> {serialCount ? serialCount : 'Serials'}
                </button>
                {canRemove && (
                    <button
                        type="button"
                        className="admin-receive-line-remove"
                        onClick={onRemove}
                        title="Remove this product"
                        aria-label="Remove this product"
                    >
                        <Trash2 size={15} />
                    </button>
                )}
            </div>

            {/* Where they go when not all in one branch. */}
            {line.split && (
                <div className="admin-receive-split">
                    <span className="admin-receive-split-title">
                        How many go to each branch
                    </span>
                    {stores.map((s) => (
                        <label key={s.id}>
                            <span>{s.name}</span>
                            <input
                                type="number"
                                min="0"
                                className="auth-text-input"
                                value={line.split[String(s.id)] ?? ''}
                                onChange={(e) =>
                                    onChange({
                                        split: {
                                            ...line.split,
                                            [String(s.id)]: e.target.value,
                                        },
                                    })
                                }
                                aria-label={`${line.name || 'This product'} to ${s.name}`}
                            />
                        </label>
                    ))}
                    <span
                        className={
                            placed === arrived && arrived > 0
                                ? 'admin-receive-split-ok'
                                : 'admin-receive-split-off'
                        }
                    >
                        {placed === arrived && arrived > 0
                            ? `✓ All ${arrived} placed`
                            : `${placed} of ${arrived} placed`}
                    </span>
                </div>
            )}

            {!line.split && arrived > 0 && nameOf(branch) && (
                <span className="admin-field-hint admin-receive-going">
                    All {arrived} go to {nameOf(branch)}
                </span>
            )}

            {line.showSerials && (
                <label className="admin-receive-serials">
                    Serial numbers — one per line (optional)
                    <textarea
                        rows={4}
                        value={line.serials}
                        onChange={(e) => onChange({ serials: e.target.value })}
                        placeholder={'SN123456\nSN123457'}
                    />
                    {line.split && (
                        <small className="admin-field-hint">
                            In the same order as the branches above: the first
                            ones typed go to the first branch.
                        </small>
                    )}
                </label>
            )}
        </div>
    );
}
