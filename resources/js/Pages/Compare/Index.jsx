import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import {
    Button,
    EmptyState,
    CardGridSkeleton,
    ProductImage,
    toast,
} from '../../Components';
import { cartService, compareService } from '../../services';
import useAppStore from '../../store/useAppStore';
import { formatBdt } from '../../utils/formatters';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import {
    Scale,
    ShoppingCart,
    Trash2,
    Plus,
    ArrowRight,
    ShieldCheck,
    CheckCircle2,
} from 'lucide-react';
import './Compare.css';

export default function Compare() {
    const [compareProducts, setCompareProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [addingId, setAddingId] = useState(null);

    useEffect(() => {
        loadCompareItems();
    }, []);

    const loadCompareItems = async () => {
        setLoading(true);
        try {
            const data = await compareService.getComparison();
            // Handle backend response or localStorage array
            const normalized = Array.isArray(data)
                ? data.map((item) => item.product || item)
                : [];
            setCompareProducts(normalized.slice(0, 4));
            useAppStore
                .getState()
                .setCompareCount(normalized.slice(0, 4).length);
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

    const handleAddToCart = async (product) => {
        setAddingId(product.id);
        try {
            await cartService.addToCart(product.id, 1);
            useAppStore.getState().fetchCartCount();
            toast.success(
                `${product.name} added to your shopping cart!`,
                'Added to Cart',
            );
        } catch (err) {
            console.error('Failed to add to cart', err);
            toast.error('Failed to add product to cart.', 'Error');
        } finally {
            setAddingId(null);
        }
    };

    // Calculate unique spec keys across all compared products
    const allSpecKeys = Array.from(
        new Set(
            compareProducts.flatMap((p) =>
                (p.specifications || []).map((s) => s.spec_name),
            ),
        ),
    );

    const emptySlotsCount = Math.max(0, 4 - compareProducts.length);

    return (
        <MainLayout>
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
                    <CardGridSkeleton count={4} className="compare-grid-skeleton" />
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

                                {/* Dynamic Specifications Rows */}
                                {allSpecKeys.map((specKey) => (
                                    <tr
                                        key={specKey}
                                        className="compare-tbody-row"
                                    >
                                        <td className="compare-td-label">
                                            {specKey}
                                        </td>
                                        {compareProducts.map((p) => {
                                            const spec = (
                                                p.specifications || []
                                            ).find(
                                                (s) => s.spec_name === specKey,
                                            );
                                            return (
                                                <td
                                                    key={p.id}
                                                    className="compare-td-val"
                                                >
                                                    {spec
                                                        ? spec.spec_value
                                                        : '—'}
                                                </td>
                                            );
                                        })}
                                        {Array.from({
                                            length: emptySlotsCount,
                                        }).map((_, idx) => (
                                            <td
                                                key={`empty-spec-${specKey}-${idx}`}
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
        </MainLayout>
    );
}
