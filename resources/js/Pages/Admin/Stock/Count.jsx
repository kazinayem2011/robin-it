import React, { useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ClipboardList, Save, RotateCcw, ScanLine } from 'lucide-react';
import Button from '@/Components/Button';
import { SearchInput } from '@/Components/SearchInput';
import EmptyState from '@/Components/EmptyState';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';
import './Count.css';

/**
 * Walking a branch's shelves and writing down what is really there.
 *
 * Corrections could only be made one product at a time through a modal, which
 * is right for "this card arrived broken" and unusable for a monthly count.
 */
export default function StockCount({
    store = null,
    stores = [],
    branch = null,
    filters = {},
    lines = [],
    recent = [],
}) {
    // Keyed by product:variant, because a variant is counted separately from
    // the product it belongs to.
    const keyOf = (l) => `${l.product_id}:${l.product_variant_id ?? ''}`;

    const [counted, setCounted] = useState({});
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [scanned, setScanned] = useState(null);
    const scanBox = useRef(null);

    const typed = (l) => counted[keyOf(l)];

    /**
     * A scan, which is a keyboard typing a code and pressing Enter.
     *
     * Counting meant finding each product in a list by name, which for a shop
     * with three near-identical sticks of RAM is where a count goes wrong — and
     * it is slow enough that counts get put off, which is worse than counting
     * badly.
     *
     * Scanning the same box twice means two of them, so a hit adds one to that
     * line rather than jumping to it and waiting. That is the whole point:
     * somebody walks the shelf with a scanner in one hand and never touches
     * the keyboard.
     */
    const onScan = async (event) => {
        if (event.key !== 'Enter') return;

        event.preventDefault();

        const code = event.target.value.trim();

        if (!code) return;

        // Cleared immediately so the next scan is not appended to this one.
        event.target.value = '';

        try {
            const res = await adminService.scanBarcode(code);
            const hit = res?.data ?? res;
            const line = lines.find(
                (l) =>
                    l.product_id === hit.product_id &&
                    (l.product_variant_id ?? null) ===
                        (hit.product_variant_id ?? null),
            );

            if (!line) {
                setScanned({
                    ok: false,
                    text: `${hit.name} is not on this branch's sheet.`,
                });
                return;
            }

            const key = keyOf(line);

            setCounted((prev) => {
                const next = Number(prev[key] ?? 0) + 1;
                setScanned({
                    ok: true,
                    text: `${line.name} — counted ${next}`,
                });
                return { ...prev, [key]: String(next) };
            });
        } catch (err) {
            setScanned({
                ok: false,
                text: err?.message || 'Nothing on the books has that barcode.',
            });
        }
    };

    /*
     * A line is only counted once somebody types into it. Blank is not zero —
     * zero means "I looked and the shelf is empty", which is a correction worth
     * making, and blank means "I have not got to this one yet".
     */
    const totals = useMemo(() => {
        let checked = 0;
        let differing = 0;
        let units = 0;
        let value = 0;

        for (const line of lines) {
            const entry = typed(line);
            if (entry === undefined || entry === '') continue;

            checked++;
            const diff = Number(entry) - line.system_quantity;
            if (diff === 0) continue;

            differing++;
            units += diff;
            if (line.unit_cost) value += line.unit_cost * diff;
        }

        return { checked, differing, units, value };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [counted, lines]);

    const save = async () => {
        const payload = lines
            .filter((l) => {
                const entry = typed(l);
                return entry !== undefined && entry !== '';
            })
            .map((l) => ({
                product_id: l.product_id,
                product_variant_id: l.product_variant_id,
                counted_quantity: Number(typed(l)),
            }));

        if (payload.length === 0) {
            toast.error('Count at least one product before saving.');
            return;
        }

        setSaving(true);
        try {
            const data = await adminService.applyStockCount({
                store_id: store.id,
                note: note.trim() || null,
                lines: payload,
            });
            toast.success(data?.message || 'Count saved.');
            setCounted({});
            setNote('');
            router.reload();
        } catch (err) {
            toast.error(err?.message || 'Could not save that count.');
        } finally {
            setSaving(false);
        }
    };

    const goToBranch = (id) =>
        router.get(
            ROUTES.ADMIN_STOCK_COUNT,
            { store: id },
            { preserveState: false },
        );

    return (
        <AdminLayout
            title="Stock take"
            subtitle="Count what is on the shelves, and correct the books in one go"
        >
            <Head title="Stock take" />

            <div className="admin-card">
                <div className="count-toolbar">
                    <div className="count-toolbar-left">
                        {branch ? (
                            <span className="count-branch-fixed">{branch}</span>
                        ) : (
                            <select
                                className="count-branch-select"
                                value={store?.id ?? ''}
                                onChange={(e) => goToBranch(e.target.value)}
                            >
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        )}

                        <SearchInput
                            value={filters.search || ''}
                            onSearch={(q) =>
                                router.get(
                                    ROUTES.ADMIN_STOCK_COUNT,
                                    {
                                        store: store?.id,
                                        search: q || undefined,
                                    },
                                    { preserveState: true, replace: true },
                                )
                            }
                            placeholder="Find a product on the sheet…"
                        />
                    </div>

                    {/*
                     * The scanner types here. Kept beside the search box rather
                     * than in a mode of its own — somebody counting a shelf
                     * scans most lines and types the odd one nobody labelled.
                     */}
                    <div className="count-scan">
                        <ScanLine size={15} />
                        <input
                            ref={scanBox}
                            type="text"
                            className="count-scan-input"
                            placeholder="Scan a barcode…"
                            onKeyDown={onScan}
                            autoFocus
                        />
                    </div>

                    <Button
                        icon={Save}
                        onClick={save}
                        disabled={saving || totals.checked === 0}
                    >
                        {saving ? 'Saving…' : `Save count (${totals.checked})`}
                    </Button>
                </div>

                {scanned && (
                    <p
                        className={`count-scan-note ${scanned.ok ? 'is-ok' : 'is-bad'}`}
                    >
                        {scanned.text}
                    </p>
                )}

                {lines.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="Nothing to count here"
                        description="This branch is not holding any stock yet. Receive a delivery into it first."
                    />
                ) : (
                    <>
                        <div className="count-sheet">
                            <table className="admin-table count-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th className="count-num">
                                            On the books
                                        </th>
                                        <th className="count-num">Counted</th>
                                        <th className="count-num">
                                            Difference
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((line) => {
                                        const entry = typed(line);
                                        const has =
                                            entry !== undefined && entry !== '';
                                        const diff = has
                                            ? Number(entry) -
                                              line.system_quantity
                                            : null;

                                        return (
                                            <tr
                                                key={keyOf(line)}
                                                className={
                                                    diff
                                                        ? 'count-row-differs'
                                                        : ''
                                                }
                                            >
                                                <td>
                                                    <div className="admin-stock-product-name">
                                                        {line.name}
                                                    </div>
                                                    {line.sku && (
                                                        <div className="admin-field-hint">
                                                            {line.sku}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="count-num count-system">
                                                    {line.system_quantity}
                                                </td>
                                                <td className="count-num">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        inputMode="numeric"
                                                        className="count-input"
                                                        value={entry ?? ''}
                                                        placeholder="—"
                                                        onChange={(e) =>
                                                            setCounted((c) => ({
                                                                ...c,
                                                                [keyOf(line)]:
                                                                    e.target
                                                                        .value,
                                                            }))
                                                        }
                                                    />
                                                </td>
                                                <td className="count-num">
                                                    {diff === null ? (
                                                        <span className="admin-field-hint">
                                                            not counted
                                                        </span>
                                                    ) : diff === 0 ? (
                                                        <span className="count-match">
                                                            matches
                                                        </span>
                                                    ) : (
                                                        <span
                                                            className={`count-diff ${diff > 0 ? 'is-up' : 'is-down'}`}
                                                        >
                                                            {diff > 0
                                                                ? '+'
                                                                : ''}
                                                            {diff}
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* What pressing Save will actually do, before it does it. */}
                        <div className="count-footer">
                            <div className="count-summary">
                                <span>
                                    <strong>{totals.checked}</strong> of{' '}
                                    {lines.length} counted
                                </span>
                                <span>
                                    <strong>{totals.differing}</strong> differ
                                </span>
                                <span>
                                    net{' '}
                                    <strong>
                                        {totals.units > 0 ? '+' : ''}
                                        {totals.units}
                                    </strong>{' '}
                                    units
                                </span>
                                <span
                                    className={
                                        totals.value < 0
                                            ? 'count-value-loss'
                                            : 'count-value-gain'
                                    }
                                >
                                    <strong>{formatBdt(totals.value)}</strong>
                                </span>
                            </div>

                            <input
                                type="text"
                                className="count-note"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="A note about this count (optional)"
                            />
                        </div>
                    </>
                )}
            </div>

            {recent.length > 0 && (
                <section className="count-recent">
                    <h2>Recent counts</h2>
                    <ul>
                        {recent.map((r) => (
                            <li key={r.id}>
                                <RotateCcw size={13} />
                                <strong>{r.reference}</strong>
                                <span>
                                    {r.store} · {r.lines_counted} counted,{' '}
                                    {r.lines_changed} corrected
                                    {r.net_units !== 0 &&
                                        ` (${r.net_units > 0 ? '+' : ''}${r.net_units} units)`}
                                </span>
                                <span className="admin-field-hint">
                                    {r.counted_by_name} · {r.when}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AdminLayout>
    );
}
