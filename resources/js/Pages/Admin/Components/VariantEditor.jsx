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

const blankRow = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    group: '',
    name: '',
    value: '',
});

/**
 * What this option has that its siblings do not.
 *
 * Only the differences: a laptop's fifty shared spec rows are typed once on
 * the product, and each build adds the half-dozen that change — processor,
 * memory, webcam, weight. On the shop page a row here replaces the product's
 * row of the same name, and its key features replace the product's, when the
 * option is chosen. Folded away, because most options (a colour, a size)
 * differ in nothing but their name.
 */
function OptionDetails({ variant, onChange }) {
    const rows = variant.specifications || [];
    const filled =
        rows.filter((r) => r.name?.trim()).length +
        (variant.key_features?.trim() ? 1 : 0);

    const setRow = (key, patch) =>
        onChange({
            specifications: rows.map((r) =>
                r.key === key ? { ...r, ...patch } : r,
            ),
        });

    return (
        <details className="admin-variant-details" open={filled > 0}>
            <summary>
                What&apos;s different on this option
                {filled > 0 && (
                    <span className="admin-variant-details-count">
                        {filled} set
                    </span>
                )}
            </summary>

            <FormInput
                label="Key features for this option"
                type="textarea"
                rows={4}
                value={variant.key_features || ''}
                onChange={(e) => onChange({ key_features: e.target.value })}
                placeholder={
                    'One per line. Leave empty to use the product’s.\nProcessor: Intel Core i7-13620H (up to 4.9GHz)'
                }
                helperText="Shown instead of the product's key features when this option is chosen."
            />

            <div className="admin-variant-spec-head">
                Spec rows that differ
                <span className="admin-field-hint">
                    A row here replaces the product&apos;s row with the same
                    name; a new name is added to its group.
                </span>
            </div>

            {rows.map((row) => (
                <div className="admin-variant-spec-row" key={row.key}>
                    <FormInput
                        label="Group"
                        value={row.group || ''}
                        onChange={(e) =>
                            setRow(row.key, { group: e.target.value })
                        }
                        placeholder="e.g. Processor"
                    />
                    <FormInput
                        label="Name"
                        value={row.name || ''}
                        onChange={(e) =>
                            setRow(row.key, { name: e.target.value })
                        }
                        placeholder="e.g. Processor Model"
                    />
                    <FormInput
                        label="Value"
                        value={row.value || ''}
                        onChange={(e) =>
                            setRow(row.key, { value: e.target.value })
                        }
                        placeholder="e.g. Core i7-13620H"
                    />
                    <button
                        type="button"
                        className="admin-receive-line-remove"
                        title="Remove this row"
                        onClick={() =>
                            onChange({
                                specifications: rows.filter(
                                    (r) => r.key !== row.key,
                                ),
                            })
                        }
                    >
                        <Trash2 size={15} />
                    </button>
                </div>
            ))}

            <Button
                type="button"
                variant="secondary"
                size="sm"
                icon={Plus}
                onClick={() =>
                    onChange({ specifications: [...rows, blankRow()] })
                }
            >
                Add a spec row
            </Button>
        </details>
    );
}

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
     * A quantity can only be typed against an option while an existing shelf is
     * being split across new ones, where it allocates stock that is already
     * there. A new product starts empty and is stocked under Purchasing.
     */
    const canEnterOpeningStock = isConverting;
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

    /*
     * Formik keys these `variants[3].sku`, so the errors object is an array
     * with a hole in it wherever a row was fine. Both that and the row being
     * touched have to hold before an error shows, the same as any other field.
     */
    const rowError = (index, field) => {
        const errors = formik.errors?.variants?.[index];
        const touched = formik.touched?.variants?.[index];

        return touched?.[field] ? errors?.[field] : undefined;
    };

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

                                {/*
                                    The server's word on this row, shown on
                                    the row. A stock code already used by
                                    another product is a rule the browser
                                    cannot check, so the only place it can
                                    come from is the refused save — and it
                                    used to arrive as a toast alone, naming
                                    the code but not the option.
                                */}
                                <FormInput
                                    label="SKU"
                                    value={variant.sku || ''}
                                    onChange={(e) =>
                                        patchVariant(variant.key, {
                                            sku: e.target.value,
                                        })
                                    }
                                    placeholder="Optional"
                                    error={rowError(index, 'sku')}
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
                                        placeholder="0"
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

                                <OptionDetails
                                    variant={variant}
                                    onChange={(patch) =>
                                        patchVariant(variant.key, patch)
                                    }
                                />
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
