import React from 'react';
import { SearchInput } from './SearchInput';
import { EmptyState } from './EmptyState';
import { Pagination } from './Pagination';
import { Database } from 'lucide-react';

/**
 * Reusable Data Table Component (SSOT).
 *
 * @param {Array} columns - Array of column configs: [{ key, header, render, align, width, className }]
 * @param {Array|Object} data - Array of rows, or Laravel paginator object { data, links, total, from, to }
 * @param {string} keyField - Unique row key identifier (default 'id')
 * @param {string} title - Optional table card title
 * @param {string} subtitle - Optional table card subtitle
 * @param {boolean} searchable - Show debounced search input
 * @param {string} searchValue - Controlled search value
 * @param {Function} onSearch - Debounced search callback function
 * @param {string} searchPlaceholder - Placeholder for search bar
 * @param {React.ReactNode} headerActions - Custom action buttons / filters slotted in top right
 * @param {boolean} pagination - Enable/disable pagination
 * @param {Array} paginationLinks - Override links array if data is array
 * @param {Function} onPageChange - Callback for custom pagination
 * @param {string} emptyTitle - Title when no records
 * @param {string} emptyDescription - Description when no records
 * @param {any} emptyIcon - Lucide icon for empty state
 * @param {string} className - Additional CSS class for table card
 */
export const DataTable = ({
    columns = [],
    data = [],
    keyField = 'id',
    title = '',
    subtitle = '',
    searchable = false,
    searchValue = '',
    onSearch,
    searchPlaceholder = 'Search records...',
    headerActions = null,
    pagination = true,
    paginationLinks = null,
    // For callers that reshape rows before passing them in — expanding a
    // product into its options, say — so the counts still describe the
    // server's paging rather than however many rows were drawn.
    paginationMeta = null,
    onPageChange,
    emptyTitle = 'No Records Found',
    emptyDescription = 'There are no items matching your current filters or query.',
    emptyIcon = Database,
    emptyActionText = '',
    onEmptyAction = null,
    className = '',
}) => {
    // Determine rows and pagination metadata (Laravel Paginator vs Array)
    const isLaravelPaginator =
        data && typeof data === 'object' && Array.isArray(data.data);
    const rows = isLaravelPaginator
        ? data.data
        : Array.isArray(data)
          ? data
          : [];
    const links = paginationLinks || (isLaravelPaginator ? data.links : []);
    const from =
        paginationMeta?.from ?? (isLaravelPaginator ? data.from : null);
    const to = paginationMeta?.to ?? (isLaravelPaginator ? data.to : null);
    const total =
        paginationMeta?.total ??
        (isLaravelPaginator ? data.total : rows.length);

    const hasHeader = title || subtitle || searchable || headerActions;

    return (
        <div className={`admin-card ${className}`.trim()}>
            {/* Table Card Header with Left-Right Alignment */}
            {hasHeader && (
                <div className="admin-card-header">
                    {(title || subtitle) && (
                        <div className="admin-card-title-group">
                            {title && (
                                <h3 className="admin-card-title">{title}</h3>
                            )}
                            {subtitle && (
                                <span className="admin-table-item-sub">
                                    {subtitle}
                                </span>
                            )}
                        </div>
                    )}

                    <div className="admin-header-actions">
                        {searchable && (
                            <SearchInput
                                value={searchValue}
                                onSearch={onSearch}
                                placeholder={searchPlaceholder}
                            />
                        )}
                        {headerActions}
                    </div>
                </div>
            )}

            {/* Table Content or Empty State */}
            {rows.length === 0 ? (
                <EmptyState
                    icon={emptyIcon}
                    title={emptyTitle}
                    description={emptyDescription}
                    actionText={emptyActionText}
                    onAction={onEmptyAction}
                />
            ) : (
                <div className="admin-table-responsive">
                    <table className="admin-table">
                        <thead>
                            <tr>
                                {columns.map((col, idx) => (
                                    <th
                                        key={col.key || idx}
                                        className={col.className || ''}
                                        style={{
                                            textAlign: col.align || 'left',
                                            width: col.width || 'auto',
                                        }}
                                    >
                                        {col.header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, rowIdx) => (
                                <tr key={row[keyField] ?? rowIdx}>
                                    {columns.map((col, colIdx) => (
                                        <td
                                            key={col.key || colIdx}
                                            className={col.className || ''}
                                            style={{
                                                textAlign: col.align || 'left',
                                            }}
                                        >
                                            {col.render
                                                ? col.render(row, rowIdx)
                                                : col.accessor
                                                  ? row[col.accessor]
                                                  : null}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Integrated Pagination Bar */}
            {pagination &&
                (links?.length > 3 ||
                    (isLaravelPaginator && data.last_page > 1)) && (
                    <Pagination
                        links={links}
                        from={from}
                        to={to}
                        total={total}
                        onPageChange={onPageChange}
                    />
                )}
        </div>
    );
};

export default DataTable;
