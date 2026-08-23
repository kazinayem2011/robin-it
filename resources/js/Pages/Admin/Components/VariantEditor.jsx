import React, { useMemo } from 'react';
import { Button, Checkbox, FormInput } from '../../../Components';
import { Plus, Trash2 } from 'lucide-react';

const newVariant = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    id: null,
    options: {},
    sku: '',
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
export default function VariantEditor({ formik, editingProduct }) {
    const isNewProduct = !editingProduct;
    const wasVariant = Boolean(editingProduct?.has_variants);
    const hasVariants = Boolean(formik.values.has_variants);

    // Only a single product being switched over needs its shelf split up.
    const isConverting = hasVariants && !wasVariant && !isNewProduct;
    const onHand = Number(editingProduct?.stock_quantity ?? 0);

    const attributes = formik.values.variant_attributes || [];
    const variants = formik.values.variants || [];

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
                onChange={(e) => toggle(e.target.checked)}
            />

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

                                {isConverting ? (
                                    <FormInput
                                        label="Units"
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
                                    <div className="form-group">
                                        <label className="form-label">
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
                            </div>
                        ))}
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() => setVariants([...variants, newVariant()])}
                    >
                        <Plus size={14} /> Add an option
                    </Button>
                </>
            )}
        </div>
    );
}
