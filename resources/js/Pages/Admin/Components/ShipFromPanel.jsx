import React, { useEffect, useState } from 'react';
import Select from '@/Components/Select';
import Button from '@/Components/Button';
import PreorderTag from '@/Components/PreorderTag';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';

const SPLIT = 'split';

/**
 * Where each item of an order comes from, and changing it.
 *
 * The client's flow: an order comes in, the admin picks the branch that sends
 * each item, and the stock comes off there. The order took from the default
 * branch (or whichever had it) when it was placed; picking another for an
 * item moves its units — back to the old branch, off the new one — until it
 * is dispatched. A line can be split, which is how two branches between them
 * cover what neither can alone, and how a "waiting for stock" line is filled
 * from a branch that now has it.
 */
export default function ShipFromPanel({ order, onChanged }) {
    const [options, setOptions] = useState(null);
    const [saving, setSaving] = useState(false);
    const [splitting, setSplitting] = useState({});

    const current = order?.ship_from ?? [];
    const canChange = Boolean(order?.can_change_ship_from);

    const load = async () => {
        try {
            setOptions((await adminService.getOrderShipFrom(order.id)) ?? null);
        } catch {
            setOptions(null);
        }
    };

    useEffect(() => {
        setSplitting({});

        if (!order?.id || !canChange) {
            setOptions(null);
            return undefined;
        }

        let cancelled = false;

        adminService
            .getOrderShipFrom(order.id)
            .then((data) => {
                if (!cancelled) setOptions(data ?? null);
            })
            .catch(() => {
                if (!cancelled) setOptions(null);
            });

        return () => {
            cancelled = true;
        };
    }, [order?.id, canChange]);

    // Nothing on a shelf: an order from before branches, or cancelled.
    if (current.length === 0 && !canChange) return null;

    const save = async (line, stores) => {
        setSaving(true);
        try {
            const res = await adminService.setOrderShipFrom(order.id, {
                lines: [{ order_item_id: line.order_item_id, stores }],
            });
            toast.success(res?.message || 'Branch changed.', 'Ships from');
            setSplitting((s) => ({ ...s, [line.order_item_id]: undefined }));
            onChanged?.(res?.data?.ship_from ?? []);
            await load();
        } catch (err) {
            toast.error(
                err?.message || 'That branch could not take this item.',
                'Not changed',
            );
        } finally {
            setSaving(false);
        }
    };

    const summary = (list) =>
        list
            .map((b) => (list.length > 1 ? `${b.name} (${b.units})` : b.name))
            .join(' · ');

    const lines = options?.lines ?? [];

    return (
        <div className="admin-detail-panel">
            <span className="admin-detail-panel-label">Ships from</span>

            {/* Before the options load, or once it can no longer change. */}
            {(!canChange || lines.length === 0) && (
                <strong className="admin-ship-from-summary">
                    {current.length ? summary(current) : '—'}
                </strong>
            )}

            {canChange &&
                lines.map((line) => (
                    <ShipFromLine
                        key={line.order_item_id}
                        line={line}
                        saving={saving}
                        split={splitting[line.order_item_id]}
                        onSplit={(draft) =>
                            setSplitting((s) => ({
                                ...s,
                                [line.order_item_id]: draft,
                            }))
                        }
                        onSave={(stores) => save(line, stores)}
                    />
                ))}

            <p className="admin-field-hint admin-ship-from-hint">
                {canChange
                    ? 'The stock comes off the branch chosen for each item. Changing it moves the units, until the order is dispatched.'
                    : 'Dispatched from here; a return or cancellation goes back to it.'}
            </p>
        </div>
    );
}

/** One item: where it comes from now, and the choice of branch. */
function ShipFromLine({ line, saving, split, onSplit, onSave }) {
    const holding = Object.fromEntries(
        line.current.map((c) => [c.id, c.units]),
    );
    const nameOf = Object.fromEntries(line.branches.map((b) => [b.id, b.name]));
    const single = line.current.length === 1 ? line.current[0].id : SPLIT;

    const choices = line.branches.map((b) => ({
        value: b.id,
        label:
            holding[b.id] === line.units
                ? `${b.name} — ships from here now`
                : `${b.name} — ${b.available} available`,
        disabled: b.available < line.units && holding[b.id] !== line.units,
    }));

    if (line.units > 1 && line.branches.length > 1) {
        choices.push({ value: SPLIT, label: 'Split between branches…' });
    }

    const choose = (value) => {
        if (value === SPLIT) {
            onSplit({ ...holding });
            return;
        }
        if (Number(value) === single) return;
        onSave({ [value]: line.units });
    };

    const splitTotal = split
        ? Object.values(split).reduce((sum, n) => sum + (Number(n) || 0), 0)
        : 0;
    const overBranch = split
        ? line.branches.find((b) => (Number(split[b.id]) || 0) > b.available)
        : null;

    return (
        <div className="admin-ship-line">
            <div className="admin-ship-line-head">
                <div>
                    <strong>{line.name}</strong>
                    <span className="admin-field-hint"> × {line.units}</span>
                    {line.owed && (
                        <div>
                            <PreorderTag
                                waiting={line.waiting_for_stock}
                                compact
                            />
                        </div>
                    )}
                </div>
                <Select
                    value={split ? SPLIT : single}
                    onChange={(e) => choose(e.target.value)}
                    options={choices}
                    disabled={saving}
                    aria-label={`Ship ${line.name} from`}
                />
            </div>

            {line.current.length > 1 && !split && (
                <span className="admin-field-hint">
                    From{' '}
                    {line.current
                        .map(
                            (c) => `${nameOf[c.id] ?? 'a branch'} (${c.units})`,
                        )
                        .join(' · ')}
                </span>
            )}

            {split && (
                <div className="admin-ship-split">
                    {line.branches.map((b) => (
                        <label key={b.id}>
                            <span>
                                {b.name}{' '}
                                <small className="admin-field-hint">
                                    ({b.available} available)
                                </small>
                            </span>
                            <input
                                type="number"
                                min="0"
                                max={b.available}
                                value={split[b.id] ?? ''}
                                onChange={(e) =>
                                    onSplit({
                                        ...split,
                                        [b.id]: e.target.value,
                                    })
                                }
                                className="auth-text-input"
                                aria-label={`${line.name} from ${b.name}`}
                            />
                        </label>
                    ))}
                    <div className="admin-ship-split-foot">
                        <span
                            className={
                                splitTotal === line.units && !overBranch
                                    ? 'admin-field-hint'
                                    : 'form-control-error'
                            }
                        >
                            {overBranch
                                ? `${overBranch.name} has only ${overBranch.available}.`
                                : `${splitTotal} of ${line.units} placed`}
                        </span>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() => onSplit(undefined)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            loading={saving}
                            disabled={
                                splitTotal !== line.units || Boolean(overBranch)
                            }
                            onClick={() =>
                                onSave(
                                    Object.fromEntries(
                                        Object.entries(split)
                                            .map(([id, n]) => [
                                                id,
                                                Number(n) || 0,
                                            ])
                                            .filter(([, n]) => n > 0),
                                    ),
                                )
                            }
                        >
                            Save split
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
