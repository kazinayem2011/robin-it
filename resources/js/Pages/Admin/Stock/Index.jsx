import { StockTabs } from './StockTabs';
import { ROUTES } from '@/constants/endpoints';
import React, { useCallback, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../../Layouts/AdminLayout';
import Button from '../../../Components/Button';
import DataTable from '../../../Components/DataTable';
import { formatBdt } from '../../../utils/formatters';
import {
    Boxes,
    PackagePlus,
    History,
    SlidersHorizontal,
    ClipboardList,
    ArrowLeftRight,
} from 'lucide-react';
import ReceiveDeliveryModal from '../Components/ReceiveDeliveryModal';
import ReceiptHistoryModal from './ReceiptHistoryModal';
import AdjustStockModal from './AdjustStockModal';
import StockLedgerModal from './StockLedgerModal';
import TransferStockModal from './TransferStockModal';

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
    stores = [],
    branch = null,
}) {
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [adjusting, setAdjusting] = useState(null);
    const [ledgerFor, setLedgerFor] = useState(null);
    const [transferring, setTransferring] = useState(null);

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

    /*
     * A row's count at one branch. An option's row reads its own; the parent
     * of a variant product adds up its options there.
     */
    const atBranch = (row, storeId) =>
        (row.stock_levels || [])
            .filter(
                (l) =>
                    Number(l.store_id) === Number(storeId) &&
                    (row._kind === 'variant'
                        ? l.product_variant_id === row._variant.id
                        : row._kind === 'parent'
                          ? Boolean(l.product_variant_id)
                          : !l.product_variant_id),
            )
            .reduce((sum, l) => sum + Number(l.quantity || 0), 0);

    /** { [storeId]: count } for a row, for the Correct window. */
    const branchLevels = (row) =>
        Object.fromEntries(stores.map((st) => [st.id, atBranch(row, st.id)]));

    /*
     * What can be done to a row, under its name: Transfer, Correct, History.
     * Words rather than icons, for whoever runs the shop; under the name
     * rather than in a column of their own, so four branches fit beside it.
     */
    const rowActions = (row) => (
        <div className="admin-stock-row-actions">
            {stores.length > 1 && (
                <button
                    type="button"
                    onClick={() =>
                        setTransferring({
                            product: row,
                            variant: row._variant || null,
                        })
                    }
                >
                    <ArrowLeftRight size={13} /> Transfer
                </button>
            )}
            <button
                type="button"
                onClick={() =>
                    setAdjusting({
                        product: row,
                        variant: row._variant || null,
                        levels: branchLevels(row),
                    })
                }
            >
                <SlidersHorizontal size={13} /> Correct
            </button>
            <button
                type="button"
                onClick={() =>
                    setLedgerFor({
                        product: row,
                        variant: row._variant || null,
                    })
                }
            >
                <History size={13} /> History
            </button>
        </div>
    );

    const columns = [
        {
            key: 'name',
            className: 'admin-stock-col-product',
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
                        {rowActions(row)}
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
                        {row._kind !== 'parent' && rowActions(row)}
                    </div>
                ),
        },
        /*
         * One column per branch: how many each has, side by side, which is
         * the question asked of this page. Below zero is units owed to
         * customers who ordered more than was in stock.
         */
        ...stores.map((store) => ({
            key: `branch-${store.id}`,
            // The first word — "Chattogram", not "Chattogram Agrabad Regional
            // Hub" — so four branches fit beside the product; the full name
            // is on each cell.
            header: store.name.split(' ')[0],
            className: 'admin-stock-col-branch',
            align: 'right',
            render: (row) => {
                const qty = atBranch(row, store.id);

                if (qty === 0) {
                    return (
                        <span className="admin-stock-zero" title={store.name}>
                            —
                        </span>
                    );
                }

                return qty < 0 ? (
                    <span
                        className="admin-stock-owed"
                        title={`${store.name}: owed to customers who ordered more than was in stock`}
                    >
                        {qty} owed
                    </span>
                ) : (
                    <span className="admin-stock-qty" title={store.name}>
                        {qty}
                    </span>
                );
            },
        })),
        {
            key: 'total',
            header: 'Total',
            align: 'right',
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
                    <strong
                        className={
                            low
                                ? 'admin-badge-stock-danger'
                                : 'admin-badge-stock-ok'
                        }
                        title={
                            low
                                ? `Running low — reorder at ${level}`
                                : undefined
                        }
                    >
                        {qty}
                        {low && ' · low'}
                    </strong>
                );
            },
        },
    ];

    /*
     * title/subtitle on the layout, like every other admin page, and no second
     * .admin-content-body inside the one the layout already provides. This
     * page drew its own heading inside a card and nested the wrapper, so it
     * carried the layout's 28/32 padding twice and sat further in than
     * everything else in the sidebar.
     */
    return (
        <AdminLayout
            title="Stock"
            subtitle="What the shop is holding, and how it got there"
        >
            <Head title="Stock & Inventory" />
            <StockTabs current={ROUTES.ADMIN_STOCK} />

            <div>
                <div className="admin-card">
                    <div className="admin-card-header">
                        <h2 className="admin-card-title-inline">
                            <Boxes size={18} className="admin-card-icon" />
                            On the shelves
                        </h2>
                        <div className="admin-header-actions">
                            <Button
                                variant="secondary"
                                icon={ClipboardList}
                                onClick={() => setHistoryOpen(true)}
                            >
                                Past deliveries
                            </Button>
                            <Button
                                icon={PackagePlus}
                                onClick={() => setReceiveOpen(true)}
                            >
                                Receive delivery
                            </Button>
                        </div>
                    </div>

                    <p className="admin-field-hint admin-stock-intro">
                        How many of each product every branch has. Stock goes up
                        when you receive a delivery and down when a customer
                        orders — by itself. Use <strong>Transfer</strong> to
                        move stock between branches, and{' '}
                        <strong>Correct</strong> to fix a count that is wrong.
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
                            {/*
                             * Confined to one branch, this is not a choice to
                             * offer — but it has to be said, or a storekeeper
                             * is shown a third of the shop's stock with no
                             * sign that is what they are looking at.
                             */}
                            {branch && (
                                <div className="admin-stock-stat admin-stock-branch-filter">
                                    <span className="admin-stock-stat-label">
                                        Showing
                                    </span>
                                    <strong className="admin-stock-branch-fixed">
                                        {branch}
                                    </strong>
                                </div>
                            )}

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

            <ReceiveDeliveryModal
                suppliers={suppliers}
                stores={stores}
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
                stores={stores}
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

            <TransferStockModal
                target={transferring}
                stores={stores}
                onClose={() => setTransferring(null)}
                onSaved={() => {
                    setTransferring(null);
                    reload();
                }}
            />
        </AdminLayout>
    );
}
