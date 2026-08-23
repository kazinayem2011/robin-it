import React, { useCallback, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../../Layouts/AdminLayout';
import { Button, DataTable, toast } from '../../../Components';
import { adminService } from '../../../services';
import { formatBdt } from '../../../utils/formatters';
import { Boxes, PackagePlus, History, SlidersHorizontal } from 'lucide-react';
import ReceiveStockModal from './ReceiveStockModal';
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
    lowStockThreshold = 10,
    adjustmentReasons = {},
}) {
    const [receiveOpen, setReceiveOpen] = useState(false);
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
    const handleSearch = useCallback((value) => {
        router.get(
            '/admin/stock',
            { search: value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['products', 'filters'],
            },
        );
    }, []);

    const reload = () => router.reload({ only: ['products'] });

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
                const low = qty <= lowStockThreshold;

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
                                : undefined
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
            header: 'Unit price',
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
                            onClick={() =>
                                setAdjusting({
                                    product: row,
                                    variant: row._variant || null,
                                })
                            }
                        >
                            <SlidersHorizontal size={14} /> Adjust
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setLedgerFor({
                                    product: row,
                                    variant: row._variant || null,
                                })
                            }
                        >
                            <History size={14} /> History
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
                        <Button onClick={() => setReceiveOpen(true)}>
                            <PackagePlus size={16} /> Receive stock
                        </Button>
                    </div>

                    <p className="admin-field-hint admin-stock-intro">
                        Stock changes only through deliveries, customer orders
                        and recorded adjustments. Every movement is kept with
                        its reason, so the number here can always be explained.
                    </p>

                    <DataTable
                        columns={columns}
                        data={rows}
                        keyField="_key"
                        searchable
                        searchValue={filters.search || ''}
                        onSearch={handleSearch}
                        searchPlaceholder="Search products..."
                        paginationLinks={products?.links || []}
                        emptyTitle="Nothing in the catalogue yet"
                        emptyDescription="Add a product first, then record the delivery that brought its stock in."
                        emptyIcon={Boxes}
                    />
                </div>
            </div>

            <ReceiveStockModal
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
        </AdminLayout>
    );
}
