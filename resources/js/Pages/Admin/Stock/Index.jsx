import React, { useCallback, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../../Layouts/AdminLayout';
import { Button, DataTable } from '../../../Components';
import { formatBdt } from '../../../utils/formatters';
import {
    Boxes,
    PackagePlus,
    History,
    SlidersHorizontal,
    ClipboardList,
} from 'lucide-react';
import ReceiveStockModal from './ReceiveStockModal';
import ReceiptHistoryModal from './ReceiptHistoryModal';
import AdjustStockModal from './AdjustStockModal';
import StockLedgerModal from './StockLedgerModal';

/**
 * Inventory.
 *
 * There is no field here that sets a quantity. Units enter through a delivery,
 * leave when a customer buys them, and are corrected only by an adjustment that
 * records a reason and an author — so the ledger always explains the balance.
 */
export default function AdminStock({
    products = {},
    filters = {},
    defaultReorderLevel = 10,
    adjustmentReasons = {},
    summary = null,
    suppliers = [],
}) {
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [adjusting, setAdjusting] = useState(null);
    const [ledgerFor, setLedgerFor] = useState(null);

    const rows = useMemo(() => {
        const list = Array.isArray(products?.data) ? products.data : [];

        // A variant product is shown as its options, since that is where the
        // stock actually sits. The parent row is a read-only total.
        return list.flatMap((product) => {
            if (!product.has_variants) {
                return [
                    { ...product, _kind: 'single', _key: `p-${product.id}` },
                ];
            }

            const variants = (product.variants || []).filter(
                (v) => v.is_active,
            );

            return [
                { ...product, _kind: 'parent', _key: `p-${product.id}` },
                ...variants.map((variant) => ({
                    ...product,
                    _kind: 'variant',
                    _key: `v-${variant.id}`,
                    _variant: variant,
                })),
            ];
        });
    }, [products]);

    // Stable identity: an inline arrow here re-fires the search effect forever.
    // The reorder filter is carried through so searching does not silently
    // drop it.
    const reorderFilter = filters.reorder;
    const handleSearch = useCallback(
        (value) => {
            router.get(
                '/admin/stock',
                {
                    search: value || undefined,
                    reorder: reorderFilter ? 1 : undefined,
                },
                {
                    preserveState: true,
                    replace: true,
                    only: ['products', 'filters', 'summary'],
                },
            );
        },
        [reorderFilter],
    );

    const reload = () => router.reload({ only: ['products', 'summary'] });

    const toggleReorderFilter = useCallback(() => {
        router.get(
            '/admin/stock',
            {
                search: filters.search || undefined,
                reorder: filters.reorder ? undefined : 1,
            },
            { preserveState: true, replace: true },
        );
    }, [filters.search, filters.reorder]);

    const columns = [
        {
            key: 'name',
            header: 'Product',
            render: (row) =>
                row._kind === 'variant' ? (
                    <div className="admin-stock-variant-row">
                        <span className="admin-stock-variant-tick">↳</span>
                        <span className="admin-stock-variant-name">
                            {row._variant.name}
                        </span>
                        {row._variant.sku && (
                            <span className="admin-stock-sku">
                                {row._variant.sku}
                            </span>
                        )}
                    </div>
                ) : (
                    <div>
                        <div className="admin-stock-product-name">
                            {row.name}
                        </div>
                        <div className="admin-field-hint">
                            {row.category?.name || 'Uncategorised'}
                            {row._kind === 'parent' &&
                                ' · stock is held per option'}
                        </div>
                    </div>
                ),
        },
        {
            key: 'on_hand',
            header: 'On hand',
            render: (row) => {
                const qty =
                    row._kind === 'variant'
                        ? row._variant.stock_quantity
                        : row.stock_quantity;

                // Each row is judged by its own reorder level, falling back to
                // the option's parent and then the store-wide default.
                const level =
                    (row._kind === 'variant'
                        ? (row._variant.reorder_level ?? row.reorder_level)
                        : row.reorder_level) ?? defaultReorderLevel;
                const low = qty <= level;

                return (
                    <span
                        className={`admin-badge-stock ${
                            low
                                ? 'admin-badge-stock-danger'
                                : 'admin-badge-stock-ok'
                        }`}
                        title={
                            row._kind === 'parent'
                                ? 'Total across every option'
                                : `Reorder at ${level}`
                        }
                    >
                        {low && '⚠️ '}
                        {qty}
                        {row._kind === 'parent' && ' total'}
                    </span>
                );
            },
        },
        {
            key: 'value',
            header: 'Price',
            render: (row) =>
                formatBdt(
                    row._kind === 'variant'
                        ? (row._variant.effective_price ?? row.price)
                        : row.price,
                ),
        },
        {
            key: 'actions',
            header: '',
            render: (row) =>
                // The parent of a variant product holds no stock of its own,
                // so there is nothing to adjust at that level.
                row._kind === 'parent' ? null : (
                    <div className="admin-input-row-flex">
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={SlidersHorizontal}
                            onClick={() =>
                                setAdjusting({
                                    product: row,
                                    variant: row._variant || null,
                                })
                            }
                        >
                            Adjust
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            icon={History}
                            onClick={() =>
                                setLedgerFor({
                                    product: row,
                                    variant: row._variant || null,
                                })
                            }
                        >
                            History
                        </Button>
                    </div>
                ),
        },
    ];

    return (
        <AdminLayout>
            <Head title="Stock & Inventory" />

            <div className="admin-content-body">
                <div className="admin-card">
                    <div className="admin-card-header">
                        <h2 className="admin-card-title-inline">
                            <Boxes size={18} className="admin-card-icon" />
                            Stock &amp; Inventory
                        </h2>
                        <div className="admin-input-row-flex">
                            <Button
                                variant="secondary"
                                icon={ClipboardList}
                                onClick={() => setHistoryOpen(true)}
                            >
                                Deliveries
                            </Button>
                            <Button
                                icon={PackagePlus}
                                onClick={() => setReceiveOpen(true)}
                            >
                                Receive stock
                            </Button>
                        </div>
                    </div>

                    <p className="admin-field-hint admin-stock-intro">
                        Stock changes only through deliveries, customer orders
                        and recorded adjustments. Every movement is kept with
                        its reason, so the number here can always be explained.
                    </p>

                    {summary && (
                        <div className="admin-stock-summary">
                            <div className="admin-stock-stat">
                                <span className="admin-stock-stat-value">
                                    {summary.units.toLocaleString()}
                                </span>
                                <span className="admin-stock-stat-label">
                                    Units on hand
                                </span>
                            </div>

                            <div className="admin-stock-stat">
                                <span className="admin-stock-stat-value">
                                    {formatBdt(summary.valuation)}
                                </span>
                                <span className="admin-stock-stat-label">
                                    Stock at cost
                                    {summary.uncosted_units > 0 &&
                                        ` \u00b7 ${summary.uncosted_units} unit(s) have no recorded cost`}
                                </span>
                            </div>

                            {/* The count doubles as the filter, so noticing
                                something needs buying and seeing what are the
                                same click. */}
                            <button
                                type="button"
                                className={`admin-stock-stat admin-stock-stat-action ${
                                    filters.reorder ? 'is-active' : ''
                                }`}
                                onClick={toggleReorderFilter}
                            >
                                <span className="admin-stock-stat-value">
                                    {summary.needs_reorder}
                                </span>
                                <span className="admin-stock-stat-label">
                                    {filters.reorder
                                        ? 'Showing items to reorder — clear'
                                        : 'Need reordering'}
                                </span>
                            </button>
                        </div>
                    )}

                    <DataTable
                        columns={columns}
                        data={rows}
                        keyField="_key"
                        searchable
                        searchValue={filters.search || ''}
                        onSearch={handleSearch}
                        searchPlaceholder="Search products..."
                        paginationLinks={products?.links || []}
                        // Rows are expanded client-side (a variant product
                        // becomes a parent row plus one per option), so the
                        // counts have to come from the paginator or the footer
                        // reports however many rows were drawn.
                        paginationMeta={{
                            from: products?.from,
                            to: products?.to,
                            total: products?.total,
                        }}
                        emptyTitle={
                            filters.reorder
                                ? 'Nothing needs reordering'
                                : 'Nothing in the catalogue yet'
                        }
                        emptyDescription={
                            filters.reorder
                                ? 'Every product is above its reorder level.'
                                : 'Add a product first, then record the delivery that brought its stock in.'
                        }
                        emptyIcon={Boxes}
                    />
                </div>
            </div>

            <ReceiveStockModal
                suppliers={suppliers}
                isOpen={receiveOpen}
                onClose={() => setReceiveOpen(false)}
                onSaved={() => {
                    setReceiveOpen(false);
                    reload();
                }}
            />

            <AdjustStockModal
                target={adjusting}
                reasons={adjustmentReasons}
                onClose={() => setAdjusting(null)}
                onSaved={() => {
                    setAdjusting(null);
                    reload();
                }}
            />

            <StockLedgerModal
                target={ledgerFor}
                onClose={() => setLedgerFor(null)}
            />

            <ReceiptHistoryModal
                isOpen={historyOpen}
                onClose={() => setHistoryOpen(false)}
            />
        </AdminLayout>
    );
}
