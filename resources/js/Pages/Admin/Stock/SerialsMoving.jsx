import React from 'react';

/**
 * The serials that go with units a count or a correction moves.
 *
 * A count or a correction that took units off a shelf left their serials "On
 * the shelf", so the serial list offered a unit the stock figure said was
 * gone. Taking units off now asks which serials went; putting them on asks
 * for the serials found. The server holds the same rules (SerialService).
 */

/**
 * How many serials must be named when units go: the shelf's serials can never
 * outnumber the units left on it. A shelf where some units were never given a
 * serial may need none.
 */
export const serialsToName = (serials = [], left = 0) =>
    Math.max(0, serials.length - Math.max(0, left));

/** Serials that left the shelf: tick which. */
export function SerialsGone({
    serials = [],
    needed,
    removed,
    chosen = [],
    onChange,
    writtenOff = false,
}) {
    if (needed <= 0 || serials.length === 0) return null;

    const done = chosen.length >= needed && chosen.length <= removed;
    const toggle = (id) =>
        onChange(
            chosen.includes(id)
                ? chosen.filter((x) => x !== id)
                : [...chosen, id],
        );

    return (
        <fieldset className="admin-transfer-serials">
            <legend>
                {needed === removed
                    ? `Which ${removed === 1 ? 'serial number is' : `${removed} serial numbers are`} no longer on the shelf?`
                    : `Tick at least ${needed} serial number${needed === 1 ? '' : 's'} no longer on the shelf`}
            </legend>
            <span
                className={`admin-field-hint ${done ? 'admin-transfer-serials-done' : ''}`}
            >
                {chosen.length} of {needed === removed ? removed : needed}{' '}
                ticked.{' '}
                {writtenOff
                    ? 'They are written off.'
                    : 'They are kept on record as Missing, and come back if the unit turns up.'}
            </span>
            <div className="admin-transfer-serials-list">
                {serials.map((sn) => (
                    <label key={sn.id}>
                        <input
                            type="checkbox"
                            className="custom-checkbox-input"
                            checked={chosen.includes(sn.id)}
                            onChange={() => toggle(sn.id)}
                        />
                        <span>{sn.serial}</span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/** Units found on the shelf: their serials. */
export function SerialsFound({ added, required, value = '', onChange }) {
    if (added <= 0) return null;

    return (
        <label className="admin-receive-serials">
            {required
                ? `Serial numbers of the ${added} found — one per line (this product has a warranty)`
                : `Serial numbers of the ${added} found — one per line (optional)`}
            <textarea
                rows={Math.min(4, Math.max(2, added))}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={'SN123456\nSN123457'}
            />
        </label>
    );
}
