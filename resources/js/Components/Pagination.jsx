import React from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import {
    paginationWindow,
    pageUrlFactory,
    readLinks,
    ELLIPSIS,
} from '../utils/paginationWindow';
import './Pagination.css';

/**
 * Pagination for both the Inertia paginator and the callback tables.
 *
 * Both modes now draw the same seven-slot window (see paginationWindow): the
 * server's own list of links is treated as a source of URLs, not as a layout.
 *
 * @param {Array} links - Laravel paginator links ([{ url, label, active }])
 * @param {number} currentPage - Active page (callback mode)
 * @param {number} totalPages - Page count (callback mode)
 * @param {Function} onPageChange - Page change callback (callback mode)
 * @param {number} from - First row index shown
 * @param {number} to - Last row index shown
 * @param {number} total - Total rows
 * @param {string} className - Optional extra class
 */
export const Pagination = ({
    links = [],
    currentPage = 1,
    totalPages = 1,
    onPageChange,
    from = null,
    to = null,
    total = null,
    className = '',
}) => {
    const usingLinks = links && links.length > 3;

    const resolved = usingLinks
        ? readLinks(links)
        : { currentPage, totalPages };

    const page = resolved.currentPage;
    const pageCount = resolved.totalPages;

    if (pageCount <= 1) return null;

    const urlFor = usingLinks ? pageUrlFactory(links) : () => null;
    const slots = paginationWindow(page, pageCount);

    const summary = total !== null && (
        <div className="pagination-summary">
            Showing <strong>{from || 1}</strong> to{' '}
            <strong>{to || total}</strong> of <strong>{total}</strong> results
        </div>
    );

    /*
     * One renderer for both modes: a paginator page is a Link so it keeps
     * Inertia's partial reload, a callback page is a button. Everything else
     * about them — classes, active state, disabled state — is identical, and
     * was previously written out twice with the two copies drifting.
     */
    const step = (target, disabled, icon, label) => {
        if (disabled) {
            return (
                <span
                    className="pagination-btn disabled"
                    aria-disabled="true"
                    aria-label={label}
                >
                    {icon}
                </span>
            );
        }

        if (usingLinks) {
            return (
                <Link
                    href={urlFor(target)}
                    preserveScroll
                    preserveState
                    className="pagination-btn"
                    aria-label={label}
                >
                    {icon}
                </Link>
            );
        }

        return (
            <button
                type="button"
                className="pagination-btn"
                onClick={() => onPageChange?.(target)}
                aria-label={label}
            >
                {icon}
            </button>
        );
    };

    const numbered = (n) => {
        const active = n === page;
        const className = `pagination-btn ${active ? 'active' : ''}`;
        const props = {
            className,
            'aria-label': `Page ${n}`,
            'aria-current': active ? 'page' : undefined,
        };

        if (usingLinks) {
            return (
                <Link
                    key={n}
                    href={urlFor(n)}
                    preserveScroll
                    preserveState
                    {...props}
                >
                    {n}
                </Link>
            );
        }

        return (
            <button
                key={n}
                type="button"
                onClick={() => onPageChange?.(n)}
                {...props}
            >
                {n}
            </button>
        );
    };

    return (
        <div
            className={`pagination-wrapper ${total !== null ? 'has-summary' : ''} ${className}`.trim()}
        >
            {summary}

            <div className="pagination-btns">
                {step(
                    page - 1,
                    page <= 1,
                    <ChevronLeft size={15} />,
                    'Previous page',
                )}

                {slots.map((slot, idx) =>
                    slot === ELLIPSIS ? (
                        <span
                            key={`gap-${idx}`}
                            className="pagination-ellipsis"
                            aria-hidden="true"
                        >
                            {ELLIPSIS}
                        </span>
                    ) : (
                        numbered(slot)
                    ),
                )}

                {step(
                    page + 1,
                    page >= pageCount,
                    <ChevronRight size={15} />,
                    'Next page',
                )}
            </div>
        </div>
    );
};

export default Pagination;
