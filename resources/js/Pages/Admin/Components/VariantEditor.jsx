import React, { useMemo } from 'react';
import Button from '../../../Components/Button';
import ImageGalleryEditor from '../../../Components/ImageGalleryEditor';
import Checkbox from '../../../Components/Checkbox';
import FormInput from '../../../Components/FormInput';
import { Plus, Trash2 } from 'lucide-react';

const newVariant = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    id: null,
    options: {},
    sku: '',
    image_url: '',
    images: [],
    reorder_level: '',
    price: '',
    discount_price: '',
    opening_stock: '',
    is_active: true,
    stock_quantity: 0,
});

/**
 * Options on a product — "16GB / 32GB", "1TB / 2TB".
 *
 * Stock is the delicate part. Switching a product that already holds units over
 * to options has to say where those units go, and the allocation must account
 * for every one of them: the shop's total cannot change just because the way it
 * is filed did. This shows the running remainder so that is obvious before
 * saving rather than as an error afterwards.
 */
export default function VariantEditor({
    formik,
    editingProduct,
    onPickImage,
    onImagesChange,
    uploading = false,
}) {
    const isNewProduct = !editingProduct;
    const wasVariant = Boolean(editingProduct?.has_variants);
    const hasVariants = Boolean(formik.values.has_variants);

    // Once a product has stock or sits on an order, switching between a single
    // pool and per-option stock would move units between shelves that past
    // records already point at. The server refuses it; the form says so first.
    const structureLocked = Boolean(editingProduct?.structure_locked);

    // Only a single product being switched over needs its shelf split up.
    const isConverting = hasVariants && !wasVariant && !isNewProduct;

    /*
     * When an opening quantity can be typed against an option: while splitting
     * an existing product's shelf, and when entering a product the shop already
     * has on hand. Never on an option that exists — that stock moves through
     * deliveries, orders and recorded adjustments.
     */
    const canEnterOpeningStock = isConverting || isNewProduct;
    const onHand = Number(editingProduct?.stock_quantity ?? 0);

    const attributes = formik.values.variant_attributes || [];

    // The `|| []` fallback builds a new array on every render, which would make
    // the allocation useMemo below recompute every time. Same shape as the
    // effect loop that took the admin down, so it is pinned here.
    const rawVariants = formik.values.variants;
    const variants = useMemo(() => rawVariants || [], [rawVariants]);

    const allocated = useMemo(
        () =>
            variants.reduce(
                (sum, v) => sum + (Number(v.opening_stock) || 0),
                0,
            ),
        [variants],
    );

    const remaining = onHand - allocated;

    const setVariants = (next) => formik.setFieldValue('variants', next);

    const patchVariant = (key, patch) =>
        setVariants(
            variants.map((v) => (v.key === key ? { ...v, ...patch } : v)),
        );

    const setAttributes = (raw) => {
        const names = raw
            .split(',')
            .map((n) => n.trim())
            .filter(Boolean);

        formik.setFieldValue('variant_attributes', names);
    };

    const toggle = (checked) => {
        formik.setFieldValue('has_variants', checked);

        if (checked && variants.length === 0) {
            formik.setFieldValue(
                'variant_attributes',
                attributes.length ? attributes : ['Option'],
            );
            setVariants([newVariant()]);
        }
    };

    return (
        <div className="admin-variant-editor">
            <Checkbox
                id="has_variants"
                name="has_variants"
                label="This product is sold in options (sizes, capacities, colours)"
                checked={hasVariants}
                disabled={structureLocked}
                onChange={(e) => toggle(e.target.checked)}
            />

            {structureLocked && (
                <p className="admin-field-hint admin-structure-locked">
                    {hasVariants
                        ? 'This product is sold in options and has stock or past orders, so it cannot be collapsed back into a single pool.'
                        : 'This product has stock or past orders, so it cannot be switched to options.'}{' '}
                    Create a new product with the structure you need and retire
                    this one.
                </p>
            )}

            {!hasVariants && wasVariant && (
                <div className="admin-variant-warning">
                    Saving will collapse the options back into one stock pool.
                    Every option&rsquo;s units are added together and kept — the
                    total does not change — and the options are retired rather
                    than deleted, so past orders still read correctly.
                </div>
            )}

            {hasVariants && (
                <>
                    <FormInput
                        label="Option names"
                        value={attributes.join(', ')}
                        onChange={(e) => setAttributes(e.target.value)}
                        placeholder="Capacity, Speed"
                        helperText="Comma separated. Every option below is described by these."
                    />

                    {isConverting && (
                        <div
                            className={`admin-variant-allocation ${
                                remaining === 0
                                    ? 'admin-variant-allocation-ok'
                                    : 'admin-variant-allocation-pending'
                            }`}
                        >
                            {remaining === 0 ? (
                                <>
                                    All {onHand} unit(s) accounted for. Nothing
                                    is created or lost by this change.
                                </>
                            ) : remaining > 0 ? (
                                <>
                                    {remaining} of {onHand} unit(s) still to
                                    allocate. The split has to cover every unit
                                    on the shelf.
                                </>
                            ) : (
                                <>
                                    {Math.abs(remaining)} unit(s) over — you
                                    have allocated {allocated} but only {onHand}{' '}
                                    are in stock.
                                </>
                            )}
                        </div>
                    )}

                    <div className="admin-variant-list">
                        {variants.map((variant, index) => (
                            <div
                                className="admin-variant-row"
                                key={variant.key || variant.id}
                            >
                                <div className="admin-variant-values">
                                    {attributes.map((attribute) => (
                                        <FormInput
                                            key={attribute}
                                            label={attribute}
                                            value={
                                                variant.options?.[attribute] ||
                                                ''
                                            }
                                            onChange={(e) =>
                                                patchVariant(variant.key, {
                                                    options: {
                                                        ...variant.options,
                                                        [attribute]:
                                                            e.target.value,
                                                    },
                                                })
                                            }
                                            placeholder={
                                                index === 0 ? 'e.g. 32GB' : ''
                                            }
                                        />
                                    ))}
                                </div>

                                <FormInput
                                    label="Price"
                                    type="number"
                                    value={variant.price ?? ''}
                                    onChange={(e) =>
                                        patchVariant(variant.key, {
                                            price: e.target.value,
                                        })
                                    }
                                    placeholder="Same as product"
                                />

                                <FormInput
                                    label="SKU"
                                    value={variant.sku || ''}
                                    onChange={(e) =>
                                        patchVariant(variant.key, {
                                            sku: e.target.value,
                                        })
                                    }
                                    placeholder="Optional"
                                />

                                <FormInput
                                    label="Reorder at"
                                    type="number"
                                    min="0"
                                    value={variant.reorder_level ?? ''}
                                    onChange={(e) =>
                                        patchVariant(variant.key, {
                                            reorder_level: e.target.value,
                                        })
                                    }
                                    placeholder="Product default"
                                />

                                {canEnterOpeningStock ? (
                                    <FormInput
                                        label={
                                            isConverting
                                                ? 'Units'
                                                : 'Opening stock'
                                        }
                                        type="number"
                                        min="0"
                                        value={variant.opening_stock ?? ''}
                                        onChange={(e) =>
                                            patchVariant(variant.key, {
                                                opening_stock: e.target.value,
                                            })
                                        }
                                    />
                                ) : (
                                    <div className="auth-form-group">
                                        <label className="auth-label">
                                            Stock
                                        </label>
                                        <div className="admin-variant-stock">
                                            {variant.id
                                                ? `${variant.stock_quantity ?? 0} on hand`
                                                : 'Receive to add'}
                                        </div>
                                    </div>
                                )}

                                <button
                                    type="button"
                                    className="admin-receive-line-remove"
                                    title={
                                        variant.stock_quantity > 0
                                            ? 'This option holds stock — it will be retired, not deleted'
                                            : 'Remove this option'
                                    }
                                    disabled={variants.length === 1}
                                    onClick={() =>
                                        setVariants(
                                            variants.filter(
                                                (v) => v.key !== variant.key,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 size={15} />
                                </button>

                                {/* Options often differ visually — a white
                                    card looks nothing like the black one — so
                                    each carries its own photos, not one shot.
                                    They lead the gallery when that option is
                                    selected. */}
                                <div className="auth-form-group admin-variant-gallery">
                                    <ImageGalleryEditor
                                        label="Photos"
                                        compact
                                        max={8}
                                        busy={uploading}
                                        images={variant.images || []}
                                        onPick={() =>
                                            onPickImage?.(variant.key)
                                        }
                                        onChange={(images) =>
                                            onImagesChange?.(
                                                variant.key,
                                                images,
                                            )
                                        }
                                        emptyHint="Uses the product's photos."
                                    />
                                </div>
                            </div>
                        ))}
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        icon={Plus}
                        onClick={() => setVariants([...variants, newVariant()])}
                    >
                        Add an option
                    </Button>
                </>
            )}
        </div>
    );
}
