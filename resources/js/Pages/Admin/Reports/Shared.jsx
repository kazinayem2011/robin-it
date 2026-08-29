import React from 'react';
import { router } from '@inertiajs/react';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { formatBdt } from '@/utils/formatters';
import './Reports.css';

/**
 * The pieces every report is made of.
 *
 * Kept together because the alternative is six screens that each style a
 * figure slightly differently, and a reader who has to work out afresh on
 * every page which number is the important one.
 */

/** The date range, in the URL so a report can be sent to somebody. */
export function PeriodPicker({ filters, path, extra = {} }) {
    const go = (patch) =>
        router.get(
            path,
            { ...filters, ...extra, ...patch },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const shortcuts = [
        ['This month', 'month'],
        ['Last month', 'last-month'],
        ['Last 7 days', 'week'],
        ['This year', 'year'],
    ];

    const jump = (key) => {
        const now = new Date();
        const iso = (d) => d.toISOString().slice(0, 10);

        const ranges = {
            month: [new Date(now.getFullYear(), now.getMonth(), 1), now],
            'last-month': [
                new Date(now.getFullYear(), now.getMonth() - 1, 1),
                new Date(now.getFullYear(), now.getMonth(), 0),
            ],
            week: [new Date(now.getTime() - 6 * 864e5), now],
            year: [new Date(now.getFullYear(), 0, 1), now],
        };

        const [from, to] = ranges[key];
        go({ from: iso(from), to: iso(to) });
    };

    return (
        <div className="rep-period">
            <div className="rep-period-dates">
                <label>
                    From
                    <input
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(e) => go({ from: e.target.value })}
                    />
                </label>
                <label>
                    To
                    <input
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(e) => go({ to: e.target.value })}
                    />
                </label>
            </div>

            <div className="rep-period-shortcuts">
                {shortcuts.map(([label, key]) => (
                    <button key={key} type="button" onClick={() => jump(key)}>
                        {label}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * One headline figure, optionally against the same figure last period.
 *
 * The comparison is the point of a report rather than decoration: a number on
 * its own says what happened, and only the change says whether that is good.
 */
export function Figure({
    label,
    value,
    previous = null,
    money = false,
    hint = null,
}) {
    const shown = money
        ? formatBdt(value)
        : Number(value ?? 0).toLocaleString();

    let change = null;

    if (previous !== null && previous !== undefined) {
        const before = Number(previous);
        const now = Number(value);

        // A rise from nothing is not "infinity per cent"; it is new business,
        // and saying so is more use than a number nobody can read.
        change =
            before === 0
                ? now > 0
                    ? { text: 'new', direction: 'up' }
                    : null
                : {
                      text: `${Math.abs(Math.round(((now - before) / before) * 100))}%`,
                      direction:
                          now > before ? 'up' : now < before ? 'down' : 'flat',
                  };
    }

    const Arrow =
        change?.direction === 'up'
            ? TrendingUp
            : change?.direction === 'down'
              ? TrendingDown
              : Minus;

    return (
        <div className="rep-figure">
            <span className="rep-figure-label">{label}</span>
            <strong className="rep-figure-value">{shown}</strong>
            {change && (
                <span className={`rep-figure-change is-${change.direction}`}>
                    <Arrow size={13} /> {change.text}
                </span>
            )}
            {hint && <small className="rep-figure-hint">{hint}</small>}
        </div>
    );
}

/**
 * Revenue over time, drawn as bars.
 *
 * Deliberately not a charting library: this is one series against a date, the
 * whole thing is forty lines of CSS, and a dependency that renders it would be
 * larger than the page it sits on.
 */
export function Bars({ series, valueKey = 'revenue', money = true }) {
    const peak = Math.max(...series.map((d) => Number(d[valueKey]) || 0), 1);

    return (
        <div className="rep-bars" role="img" aria-label="Daily totals">
            {series.map((day) => {
                const value = Number(day[valueKey]) || 0;

                return (
                    <div
                        key={day.on}
                        className="rep-bar"
                        /* Title, so the exact figure is a hover away without
                           needing a tooltip library or a legend. */
                        title={`${day.on}: ${money ? formatBdt(value) : value}`}
                    >
                        <div
                            className="rep-bar-fill"
                            style={{
                                height: `${Math.max((value / peak) * 100, value > 0 ? 2 : 0)}%`,
                            }}
                        />
                    </div>
                );
            })}
        </div>
    );
}

/** A plain table, because most of a report is one. */
export function Table({
    columns,
    rows,
    empty = 'Nothing to show for this period.',
}) {
    if (!rows || rows.length === 0) {
        return <p className="rep-empty">{empty}</p>;
    }

    return (
        <div className="rep-table-wrap">
            <table className="rep-table">
                <thead>
                    <tr>
                        {columns.map((c) => (
                            <th
                                key={c.key}
                                className={
                                    c.align === 'right' ? 'is-right' : ''
                                }
                            >
                                {c.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={row.id ?? row.key ?? i}>
                            {columns.map((c) => (
                                <td
                                    key={c.key}
                                    className={
                                        c.align === 'right' ? 'is-right' : ''
                                    }
                                >
                                    {c.render ? c.render(row) : row[c.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** A figure the shop cannot know, said plainly rather than shown as zero. */
export function Unknown({ children = '—' }) {
    return <span className="rep-unknown">{children}</span>;
}
