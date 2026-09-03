import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import ProductImage from '../../Components/ProductImage';
import { CardGridSkeleton } from '../../Components/Skeleton';
import { toast } from '../../Components/Toast';
import { compareService, productService } from '../../services';
import useAppStore from '../../store/useAppStore';
import { useAddToCart } from '../../hooks';
import { formatBdt } from '../../utils/formatters';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import {
    Scale,
    ShoppingCart,
    Trash2,
    Plus,
    ShieldCheck,
    CheckCircle2,
} from 'lucide-react';
import './Compare.css';

export default function Compare() {
    const [compareProducts, setCompareProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [addingId, setAddingId] = useState(null);
    const addToCart = useAddToCart();

    useEffect(() => {
        loadCompareItems();
    }, []);

    const loadCompareItems = async () => {
        setLoading(true);
        try {
            const data = await compareService.getComparison();
            // Handle backend response or localStorage array
            const normalized = Array.isArray(data)
                ? data.map((item) => item.product || item).slice(0, 4)
                : [];

            /*
             * Fetched in full, not read from what was stored.
             *
             * Comparing is kept in the browser, and what goes in is the card's
             * payload — a name, a price, a picture and three specs flattened
             * to strings. The table asked those objects for their
             * specifications and their answers to the shelf's questions, and
             * they have neither, so every product compared on four fixed rows
             * and nothing else. The whole page was four rows deep however much
             * the shop knew about the things in it.
             *
             * Each product is asked for once, in parallel. One that cannot be
             * fetched — withdrawn since it was added — keeps what was stored,
             * so it still appears with its name and price rather than
             * vanishing from a comparison the shopper built.
             */
            const full = await Promise.all(
                normalized.map(async (p) => {
                    if (!p?.slug) return p;

                    try {
                        return (
                            (await productService.getProductBySlug(p.slug)) || p
                        );
                    } catch {
                        return p;
                    }
                }),
            );

            setCompareProducts(full);
            useAppStore.getState().setCompareCount(full.length);
        } catch (e) {
            console.error('Failed to load comparison list', e);
            setCompareProducts([]);
        } finally {
            setLoading(false);
        }
    };

    const handleRemove = async (productId) => {
        try {
            await compareService.removeFromComparison(productId);
            const updated = compareProducts.filter((p) => p.id !== productId);
            setCompareProducts(updated);
            useAppStore.getState().setCompareCount(updated.length);
            toast.info('Product removed from comparison matrix.');
        } catch (err) {
            toast.error('Failed to remove product.');
        }
    };

    const handleClearAll = async () => {
        try {
            for (const p of compareProducts) {
                await compareService.removeFromComparison(p.id);
            }
            setCompareProducts([]);
            useAppStore.getState().setCompareCount(0);
            toast.info('Comparison matrix cleared.');
        } catch (e) {
            setCompareProducts([]);
            useAppStore.getState().setCompareCount(0);
        }
    };

    /*
     * Through the shared hook, not a fourth copy of the same call.
     *
     * This posted a product with no option, which the server refuses for
     * anything sold by one — and then reported "Failed to add product to
     * cart", throwing away the reason. Comparing two graphics cards and being
     * unable to buy either, with no explanation, is the worst place for it.
     *
     * The hook adds the default when a product has a single option, raises the
     * picker when there is a real choice, and shows what the server actually
     * said when it refuses.
     */
    const handleAddToCart = async (product) => {
        setAddingId(product.id);
        try {
            await addToCart(product);
        } finally {
            setAddingId(null);
        }
    };

    // Calculate unique spec keys across all compared products
    /*
     * Every row worth comparing, in the order they are worth reading.
     *
     * Two sources, because the shop keeps two kinds of fact. The shelf's own
     * questions — Processor Type, Display Size, Wi-Fi Standard — are
     * structured, asked of every product on that shelf, and are what the
     * sidebar filters on; they go first, because two products answering the
     * same question are exactly what a comparison is for. Free-text
     * specifications follow.
     *
     * A row appears if any product being compared has an answer, and the ones
     * that do not show an em dash. Dropping rows only some products answer
     * would hide the difference the shopper came to see.
     */
    const attributeRows = Array.from(
        new Set(
            compareProducts.flatMap((p) =>
                (p.attribute_values || [])
                    .map((v) => v.attribute?.name)
                    .filter(Boolean),
            ),
        ),
    );

    const specRows = Array.from(
        new Set(
            compareProducts.flatMap((p) =>
                (p.specifications || []).map((sp) => sp.name).filter(Boolean),
            ),
        ),
    );

    const emptySlotsCount = Math.max(0, 4 - compareProducts.length);

    return (
        <>
            <Head
                title={`Compare Hardware (Max 4 Items) — ${siteConfig.name}`}
            />

            <div className="compare-page-wrapper container">
                <div className="compare-header-bar">
                    <div>
                        <h1 className="compare-title">
                            Hardware Comparison Matrix
                        </h1>
                        <span className="compare-count">
                            Comparing {compareProducts.length} of 4 maximum
                            hardware units side-by-side
                        </span>
                    </div>
                    {compareProducts.length > 0 && (
                        <div style={{ display: 'flex', gap: '10px' }}>
                            <button
                                type="button"
                                onClick={handleClearAll}
                                className="btn btn-outline btn-sm"
                            >
                                <Trash2 size={14} /> Clear Matrix
                            </button>
                            {compareProducts.length < 4 && (
                                <Link
                                    href={ROUTES.SHOP}
                                    className="btn btn-secondary btn-sm"
                                >
                                    <Plus size={14} /> Add More (
                                    {4 - compareProducts.length} Slots Left)
                                </Link>
                            )}
                        </div>
                    )}
                </div>

                {loading ? (
                    <CardGridSkeleton
                        count={4}
                        className="compare-grid-skeleton"
                    />
                ) : compareProducts.length === 0 ? (
                    <EmptyState
                        icon={Scale}
                        title="Comparison matrix is empty"
                        description="Select up to 4 hardware products from the catalog or product pages to analyze specifications side-by-side."
                        actionLabel="Browse Hardware Catalog"
                        actionHref={ROUTES.SHOP}
                    />
                ) : (
                    <div className="compare-table-wrapper">
                        <table className="compare-table">
                            <thead>
                                <tr className="compare-thead-row">
                                    <th className="compare-th-attribute">
                                        Hardware
                                    </th>
                                    {compareProducts.map((product) => (
                                        <th
                                            key={product.id}
                                            className="compare-th-product"
                                        >
                                            <div className="compare-product-box">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleRemove(product.id)
                                                    }
                                                    className="compare-remove-btn"
                                                    title="Remove from comparison"
                                                    aria-label="Remove"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                                <ProductImage
                                                    product={product}
                                                    alt={product.name}
                                                    className="compare-product-img"
                                                />
                                                <h4 className="compare-product-name">
                                                    {product.name}
                                                </h4>
                                                <div className="compare-product-price">
                                                    {formatBdt(
                                                        product.discount_price ||
                                                            product.price,
                                                    )}
                                                </div>
                                                <Button
                                                    variant="primary"
                                                    size="sm"
                                                    fullWidth
                                                    icon={ShoppingCart}
                                                    loading={
                                                        addingId === product.id
                                                    }
                                                    onClick={() =>
                                                        handleAddToCart(product)
                                                    }
                                                >
                                                    Add to Cart
                                                </Button>
                                            </div>
                                        </th>
                                    ))}

                                    {/* Empty Slots */}
                                    {Array.from({
                                        length: emptySlotsCount,
                                    }).map((_, idx) => (
                                        <th
                                            key={`empty-${idx}`}
                                            className="compare-th-product"
                                            style={{ verticalAlign: 'middle' }}
                                        >
                                            <Link
                                                href={ROUTES.SHOP}
                                                className="compare-empty-slot"
                                            >
                                                <div className="compare-empty-slot-icon">
                                                    <Plus size={22} />
                                                </div>
                                                <div>
                                                    <strong
                                                        style={{
                                                            display: 'block',
                                                            fontSize: '0.88rem',
                                                        }}
                                                    >
                                                        Add Product
                                                    </strong>
                                                    <small
                                                        style={{
                                                            color: 'var(--gray-500)',
                                                        }}
                                                    >
                                                        Slot{' '}
                                                        {compareProducts.length +
                                                            idx +
                                                            1}{' '}
                                                        of 4
                                                    </small>
                                                </div>
                                            </Link>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {/* Brand Row */}
                                <tr className="compare-tbody-row">
                                    <td className="compare-td-label">Brand</td>
                                    {compareProducts.map((p) => (
                                        <td
                                            key={p.id}
                                            className="compare-td-val"
                                        >
                                            {p.brand?.name || 'Genuine Brand'}
                                        </td>
                                    ))}
                                    {Array.from({
                                        length: emptySlotsCount,
                                    }).map((_, idx) => (
                                        <td
                                            key={`empty-brand-${idx}`}
                                            className="compare-td-val"
                                            style={{ color: 'var(--gray-400)' }}
                                        >
                                            —
                                        </td>
                                    ))}
                                </tr>

                                {/* Category Row */}
                                <tr className="compare-tbody-row">
                                    <td className="compare-td-label">
                                        Category
                                    </td>
                                    {compareProducts.map((p) => (
                                        <td
                                            key={p.id}
                                            className="compare-td-val"
                                        >
                                            {p.category?.name || 'Hardware'}
                                        </td>
                                    ))}
                                    {Array.from({
                                        length: emptySlotsCount,
                                    }).map((_, idx) => (
                                        <td
                                            key={`empty-cat-${idx}`}
                                            className="compare-td-val"
                                            style={{ color: 'var(--gray-400)' }}
                                        >
                                            —
                                        </td>
                                    ))}
                                </tr>

                                {/* Stock Status */}
                                <tr className="compare-tbody-row">
                                    <td className="compare-td-label">
                                        Availability
                                    </td>
                                    {compareProducts.map((p) => (
                                        <td
                                            key={p.id}
                                            className="compare-td-val compare-stock-val"
                                        >
                                            <CheckCircle2
                                                size={13}
                                                style={{
                                                    display: 'inline',
                                                    marginRight: 4,
                                                }}
                                            />
                                            {p.stock_quantity > 0
                                                ? `${p.stock_quantity} Units in Stock`
                                                : 'In Stock'}
                                        </td>
                                    ))}
                                    {Array.from({
                                        length: emptySlotsCount,
                                    }).map((_, idx) => (
                                        <td
                                            key={`empty-stock-${idx}`}
                                            className="compare-td-val"
                                            style={{ color: 'var(--gray-400)' }}
                                        >
                                            —
                                        </td>
                                    ))}
                                </tr>

                                {/* Warranty */}
                                <tr className="compare-tbody-row">
                                    <td className="compare-td-label">
                                        Warranty Protection
                                    </td>
                                    {compareProducts.map((p) => (
                                        <td
                                            key={p.id}
                                            className="compare-td-val"
                                        >
                                            <ShieldCheck
                                                size={14}
                                                color="#16a34a"
                                                style={{
                                                    display: 'inline',
                                                    marginRight: 4,
                                                }}
                                            />
                                            Official Direct Brand Warranty
                                        </td>
                                    ))}
                                    {Array.from({
                                        length: emptySlotsCount,
                                    }).map((_, idx) => (
                                        <td
                                            key={`empty-warranty-${idx}`}
                                            className="compare-td-val"
                                            style={{ color: 'var(--gray-400)' }}
                                        >
                                            —
                                        </td>
                                    ))}
                                </tr>

                                {/* The shelf's questions, then the free-text
                                    specifications. Both read the column names
                                    the tables actually use — this asked for
                                    spec_name and spec_value, which are not
                                    columns, so no row ever appeared even for a
                                    product carrying twenty specifications. */}
                                {[
                                    ...attributeRows.map((name) => ({
                                        name,
                                        answer: (p) =>
                                            (p.attribute_values || [])
                                                .filter(
                                                    (v) =>
                                                        v.attribute?.name ===
                                                        name,
                                                )
                                                .map((v) => v.label)
                                                .join(', '),
                                    })),
                                    ...specRows.map((name) => ({
                                        name,
                                        answer: (p) =>
                                            (p.specifications || []).find(
                                                (sp) => sp.name === name,
                                            )?.value,
                                    })),
                                ].map(({ name, answer }) => (
                                    <tr
                                        key={name}
                                        className="compare-tbody-row"
                                    >
                                        <td className="compare-td-label">
                                            {name}
                                        </td>
                                        {compareProducts.map((p) => (
                                            <td
                                                key={p.id}
                                                className="compare-td-val"
                                            >
                                                {answer(p) || '—'}
                                            </td>
                                        ))}
                                        {Array.from({
                                            length: emptySlotsCount,
                                        }).map((_, idx) => (
                                            <td
                                                key={`empty-row-${name}-${idx}`}
                                                className="compare-td-val"
                                                style={{
                                                    color: 'var(--gray-400)',
                                                }}
                                            >
                                                —
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
Compare.layout = mainLayout;
