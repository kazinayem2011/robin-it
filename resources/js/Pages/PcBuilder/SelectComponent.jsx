import React, { useState, useEffect, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import ProductImage from '../../Components/ProductImage';
import { ProductCardSkeleton } from '../../Components/Skeleton';
import { toast } from '../../Components/Toast';
import { pcBuilderService } from '../../services';
import useAppStore from '../../store/useAppStore';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { ArrowLeft, Search, Plus, Check } from 'lucide-react';
import './PcBuilder.css';

export default function SelectComponent({ categorySlug }) {
    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    const [showIncompatible, setShowIncompatible] = useState(false);

    const pcBuilderItems = useAppStore((state) => state.pcBuilderItems);

    /*
     * The rest of the build, as { slot: productId }, excluding the slot being
     * filled. Memoised so it can be a dependency of the fetch below: rebuilt
     * inline it was a new object every render, which is why the effect could
     * not depend on it and the candidate list kept the compatibility verdicts
     * it was given for a build the shopper had since changed.
     */
    const selection = useMemo(
        () =>
            pcBuilderItems.reduce((acc, item) => {
                if (item.componentId !== categorySlug) {
                    acc[item.componentId] = item.product.id;
                }
                return acc;
            }, {}),
        [pcBuilderItems, categorySlug],
    );

    // Stable serialisation, so an unchanged build does not refire the request.
    const selectionKey = JSON.stringify(selection);

    const compatibleCount = products.filter(
        (p) => p.compatibility?.status !== 'fail',
    ).length;
    const incompatibleCount = products.length - compatibleCount;
    const visibleProducts = showIncompatible
        ? products
        : products.filter((p) => p.compatibility?.status !== 'fail');

    useEffect(() => {
        const fetchComponents = async () => {
            setLoading(true);
            try {
                const data = await pcBuilderService.getComponents(
                    categorySlug,
                    search,
                    selection,
                );
                if (data && Array.isArray(data)) {
                    setProducts(data);
                }
            } catch (error) {
                console.error('Failed to load components', error);
            } finally {
                setLoading(false);
            }
        };

        const timer = setTimeout(() => {
            fetchComponents();
        }, 300);

        return () => clearTimeout(timer);
        // selection is compared by selectionKey, its stable serialisation.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [categorySlug, search, selectionKey]);

    const handleSelectProduct = (product) => {
        const compat = product.compatibility || {};

        // Selecting a conflicting part is allowed only deliberately, and the
        // reason is spelled out rather than silently accepted.
        if (compat.status === 'fail') {
            const proceed = window.confirm(
                `${compat.reason}\n\nAdd it anyway?`,
            );
            if (!proceed) return;
        }

        // Remove existing item for this slot if any, then add
        const currentItems = useAppStore.getState().pcBuilderItems;
        const filtered = currentItems.filter(
            (i) => i.componentId !== categorySlug,
        );
        useAppStore.setState({
            pcBuilderItems: [
                ...filtered,
                { id: product.id, componentId: categorySlug, product },
            ],
        });

        // Confirming past a known conflict should not be congratulated.
        if (compat.status === 'fail') {
            toast.warning(
                `Added despite a compatibility conflict — ${compat.reason}`,
                'Incompatible Part Added',
            );
        } else if (compat.status === 'unknown') {
            toast.warning(compat.reason, 'Compatibility Not Verified');
        } else {
            toast.success(
                `Selected "${product.name}" for your rig!`,
                'Component Added',
            );
        }

        router.visit(ROUTES.PC_BUILDER);
    };

    return (
        <>
            <Head title={`Select Component — ${siteConfig.name}`} />

            <div className="pc-builder-wrapper container">
                <div className="component-select-back-bar">
                    <Link
                        href={ROUTES.PC_BUILDER}
                        className="btn btn-secondary btn-sm"
                    >
                        <ArrowLeft size={14} /> Back to PC Builder
                    </Link>
                </div>

                {/* Search & Filter Header */}
                <div className="component-select-header">
                    <div>
                        <h2 className="component-select-title">
                            Choose {categorySlug.replace(/-/g, ' ')}
                        </h2>
                        <span className="component-select-count">
                            {incompatibleCount > 0
                                ? `${compatibleCount} of ${products.length} compatible with your current build`
                                : `Showing ${products.length} components`}
                        </span>
                    </div>

                    <div className="component-select-search-box">
                        <Search
                            size={16}
                            className="component-select-search-icon"
                        />
                        <input
                            type="text"
                            placeholder="Filter components by name..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="form-control-input component-select-search-input"
                        />
                    </div>
                </div>

                {/* Products Grid */}
                {loading ? (
                    <div className="standard-products-grid">
                        {Array.from({ length: 6 }).map((_, i) => (
                            <ProductCardSkeleton key={i} />
                        ))}
                    </div>
                ) : products.length === 0 ? (
                    <EmptyState
                        title="No matching components found"
                        description="Try searching with a different keyword or return to the PC builder."
                        actionLabel="Back to Rig"
                        actionHref={ROUTES.PC_BUILDER}
                    />
                ) : (
                    <div className="component-select-list">
                        {incompatibleCount > 0 && (
                            <label className="component-compat-toggle">
                                <input
                                    type="checkbox"
                                    checked={showIncompatible}
                                    onChange={(e) =>
                                        setShowIncompatible(e.target.checked)
                                    }
                                />
                                <span>
                                    Show {incompatibleCount} part
                                    {incompatibleCount === 1 ? '' : 's'} that
                                    won&apos;t fit your current build
                                </span>
                            </label>
                        )}

                        {visibleProducts.map((product) => {
                            const isSelected = pcBuilderItems.some(
                                (i) =>
                                    i.componentId === categorySlug &&
                                    i.product.id === product.id,
                            );
                            const price = product.raw_price ?? 0;
                            const compat = product.compatibility || {};
                            const blocked = compat.status === 'fail';

                            return (
                                <div
                                    key={product.id}
                                    className={`component-select-card ${isSelected ? 'selected' : ''} ${
                                        blocked ? 'is-incompatible' : ''
                                    } ${compat.status === 'unknown' ? 'is-unverified' : ''}`}
                                >
                                    <div className="component-select-left">
                                        <ProductImage
                                            product={product}
                                            alt={product.name}
                                            className="component-select-img"
                                        />
                                        <div>
                                            <h4 className="component-select-item-title">
                                                {product.name}
                                            </h4>
                                            <div className="component-select-meta-row">
                                                <span>
                                                    Brand:{' '}
                                                    <strong>
                                                        {product.brand ||
                                                            'Genuine'}
                                                    </strong>
                                                </span>
                                                <span>•</span>
                                                <span
                                                    className="component-select-stock-tag"
                                                    style={
                                                        product.inStock
                                                            ? undefined
                                                            : {
                                                                  color: '#b42318',
                                                              }
                                                    }
                                                >
                                                    {product.inStock
                                                        ? `In Stock (${product.stockQuantity})`
                                                        : 'Out of Stock'}
                                                </span>
                                            </div>

                                            {compat.reason && (
                                                <small
                                                    className={`component-compat-note ${
                                                        blocked
                                                            ? 'is-error'
                                                            : 'is-warning'
                                                    }`}
                                                >
                                                    {blocked ? '✕ ' : '⚠ '}
                                                    {compat.reason}
                                                </small>
                                            )}
                                        </div>
                                    </div>

                                    <div className="component-select-right">
                                        <div className="component-select-price-box">
                                            <span className="component-select-price">
                                                {formatBdt(price)}
                                            </span>
                                            {product.discount_price &&
                                                product.discount_price <
                                                    product.price && (
                                                    <span className="component-select-old-price">
                                                        {formatBdt(
                                                            product.price,
                                                        )}
                                                    </span>
                                                )}
                                        </div>

                                        <Button
                                            variant={
                                                isSelected ? 'dark' : 'primary'
                                            }
                                            size="md"
                                            icon={isSelected ? Check : Plus}
                                            onClick={() =>
                                                handleSelectProduct(product)
                                            }
                                        >
                                            {isSelected
                                                ? 'Selected'
                                                : 'Add to Rig'}
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
SelectComponent.layout = mainLayout;
