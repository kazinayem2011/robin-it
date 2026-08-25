import React from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import './Pagination.css';

/**
 * Reusable Pagination Component (Supports both Laravel Inertia links & numeric callbacks).
 *
 * @param {Array} links - Laravel paginator links array ([{ url, label, active }])
 * @param {number} currentPage - Current active page number (for callback mode)
 * @param {number} totalPages - Total pages count (for callback mode)
 * @param {Function} onPageChange - Page change callback (for callback mode)
 * @param {number} from - From index (e.g. 1)
 * @param {number} to - To index (e.g. 10)
 * @param {number} total - Total records count (e.g. 48)
 * @param {string} className - Optional CSS class
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
    // If Laravel Inertia paginator links are provided
    if (links && links.length > 3) {
        return (
            <div
                className={`pagination-wrapper ${total !== null ? 'has-summary' : ''} ${className}`.trim()}
            >
                {total !== null && (
                    <div className="pagination-summary">
                        Showing <strong>{from || 1}</strong> to{' '}
                        <strong>{to || total}</strong> of{' '}
                        <strong>{total}</strong> results
                    </div>
                )}

                <div className="pagination-btns">
                    {links.map((link, idx) => {
                        // Decode HTML entities (e.g. &laquo; Previous -> Previous)
                        let cleanLabel = link.label
                            .replace(/&laquo;/g, '')
                            .replace(/&raquo;/g, '')
                            .trim();

                        const isPrev =
                            link.label.includes('Previous') ||
                            link.label.includes('&laquo;');
                        const isNext =
                            link.label.includes('Next') ||
                            link.label.includes('&raquo;');

                        if (!link.url) {
                            return (
                                <span
                                    key={idx}
                                    className="pagination-btn disabled"
                                    aria-disabled="true"
                                >
                                    {isPrev ? (
                                        <ChevronLeft size={15} />
                                    ) : isNext ? (
                                        <ChevronRight size={15} />
                                    ) : (
                                        cleanLabel
                                    )}
                                </span>
                            );
                        }

                        return (
                            <Link
                                key={idx}
                                href={link.url}
                                preserveScroll
                                preserveState
                                className={`pagination-btn ${link.active ? 'active' : ''}`}
                            >
                                {isPrev ? (
                                    <ChevronLeft size={15} />
                                ) : isNext ? (
                                    <ChevronRight size={15} />
                                ) : (
                                    cleanLabel
                                )}
                            </Link>
                        );
                    })}
                </div>
            </div>
        );
    }

    // Otherwise, render callback-based pagination if totalPages > 1
    if (totalPages > 1) {
        const pages = [];
        const maxVisible = 5;
        let start = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let end = Math.min(totalPages, start + maxVisible - 1);

        if (end - start + 1 < maxVisible) {
            start = Math.max(1, end - maxVisible + 1);
        }

        for (let i = start; i <= end; i++) {
            pages.push(i);
        }

        return (
            <div
                className={`pagination-wrapper ${total !== null ? 'has-summary' : ''} ${className}`.trim()}
            >
                {total !== null && (
                    <div className="pagination-summary">
                        Showing <strong>{from || 1}</strong> to{' '}
                        <strong>{to || total}</strong> of{' '}
                        <strong>{total}</strong> results
                    </div>
                )}

                <div className="pagination-btns">
                    <button
                        type="button"
                        className="pagination-btn"
                        disabled={currentPage <= 1}
                        onClick={() =>
                            onPageChange && onPageChange(currentPage - 1)
                        }
                        aria-label="Previous page"
                    >
                        <ChevronLeft size={15} />
                    </button>

                    {start > 1 && (
                        <>
                            <button
                                type="button"
                                className={`pagination-btn ${currentPage === 1 ? 'active' : ''}`}
                                onClick={() => onPageChange && onPageChange(1)}
                            >
                                1
                            </button>
                            {start > 2 && (
                                <span className="pagination-ellipsis">...</span>
                            )}
                        </>
                    )}

                    {pages.map((p) => (
                        <button
                            key={p}
                            type="button"
                            className={`pagination-btn ${currentPage === p ? 'active' : ''}`}
                            onClick={() => onPageChange && onPageChange(p)}
                        >
                            {p}
                        </button>
                    ))}

                    {end < totalPages && (
                        <>
                            {end < totalPages - 1 && (
                                <span className="pagination-ellipsis">...</span>
                            )}
                            <button
                                type="button"
                                className={`pagination-btn ${currentPage === totalPages ? 'active' : ''}`}
                                onClick={() =>
                                    onPageChange && onPageChange(totalPages)
                                }
                            >
                                {totalPages}
                            </button>
                        </>
                    )}

                    <button
                        type="button"
                        className="pagination-btn"
                        disabled={currentPage >= totalPages}
                        onClick={() =>
                            onPageChange && onPageChange(currentPage + 1)
                        }
                        aria-label="Next page"
                    >
                        <ChevronRight size={15} />
                    </button>
                </div>
            </div>
        );
    }

    return null;
};

export default Pagination;
