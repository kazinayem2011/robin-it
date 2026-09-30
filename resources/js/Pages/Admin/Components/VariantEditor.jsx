import React, { useEffect, useMemo, useState } from 'react';
import Button from '../../../Components/Button';
import ImageGalleryEditor from '../../../Components/ImageGalleryEditor';
import Checkbox from '../../../Components/Checkbox';
import FormInput from '../../../Components/FormInput';
import { Plus, Trash2 } from 'lucide-react';
import { isColourName } from '../../../utils/optionColour';

const newVariant = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    id: null,
    options: {},
    sku: '',
    mpn: '',
    swatch: '',
    image_url: '',
    images: [],
    reorder_level: '',
    price: '',
    discount_price: '',
    opening_stock: '',
    is_active: true,
    stock_quantity: 0,
});

/*
 * A first guess from the name, so typing "Blue Titanium" fills the swatch in
 * and most colours need no picking at all. The first word that is a colour
 * wins; anything unknown is left for the picker.
 */
const COLOUR_GUESSES = {
    black: '#1d1d1f',
    midnight: '#1f2a44',
    white: '#f5f5f0',
    starlight: '#ede6da',
    silver: '#c7c9cc',
    grey: '#8e8e93',
    gray: '#8e8e93',
    graphite: '#4a4a4f',
    titanium: '#8a8a8f',
    natural: '#bfb5a6',
    gold: '#d4b26a',
    blue: '#3d5a80',
    navy: '#1f3a5f',
    red: '#c0392b',
    green: '#2e7d32',
    pink: '#f2b8c6',
    purple: '#7e57c2',
    yellow: '#f2c94c',
    orange: '#e67e22',
    brown: '#795548',
    beige: '#d8c7a6',
};

export const guessSwatch = (value) =>
    (value || '')
        .toLowerCase()
        .split(/[^a-z]+/)
        .map((word) => COLOUR_GUESSES[word])
        .find(Boolean) || '';

const parseNames = (raw) =>
    raw
        .split(',')
        .map((n) => n.trim())
        .filter(Boolean);

const sameColour = (a, b) =>
    (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase();

/*
 * A control with no label of its own, lined up with the fields beside it: an
 * empty label line on top, so its box starts where theirs do.
 */
const Unlabelled = ({ className = '', children }) => (
    <div className={`auth-form-group ${className}`.trim()}>
        <span className="auth-label" aria-hidden="true">
            &nbsp;
        </span>
        {children}
    </div>
);

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

    /*
     * Typing a colour: the swatch another row already has for it, else a
     * guess from the name — unless someone picked this row's swatch by hand,
     * which a typo fix in the name must not undo.
     */
    const setColourValue = (variant, attribute, value) => {
        const before = variant.options?.[attribute];
        const picked = variant.swatch && variant.swatch !== guessSwatch(before);
        const sibling = variants.find(
            (v) =>
                v.key !== variant.key &&
                v.swatch &&
                value.trim() &&
                sameColour(v.options?.[attribute], value),
        )?.swatch;

        patchVariant(variant.key, {
            options: { ...variant.options, [attribute]: value },
            swatch: sibling || (picked ? variant.swatch : guessSwatch(value)),
        });
    };

    // A picked swatch goes to every row of that colour: eight rows of
    // storage × colour need four picks, not eight.
    const setSwatch = (variant, attribute, swatch) => {
        const colour = variant.options?.[attribute];

        setVariants(
            variants.map((v) =>
                v.key === variant.key ||
                (colour?.trim() && sameColour(v.options?.[attribute], colour))
                    ? { ...v, swatch }
                    : v,
            ),
        );
    };

    /*
     * The box keeps what was typed; the names are read from it. It showed
     * the names joined back up instead, so the comma and space after
     * "Storage" were dropped before the next key — "Storage, Color" came out
     * "StorageColor", and a second option name could not be typed at all.
     */
    const [namesText, setNamesText] = useState(attributes.join(', '));
    const attributesKey = JSON.stringify(attributes);

    // A product loaded or copied into the form brings its own names.
    useEffect(() => {
        setNamesText((typed) =>
            JSON.stringify(parseNames(typed)) === attributesKey
                ? typed
                : JSON.parse(attributesKey).join(', '),
        );
    }, [attributesKey]);

    const setAttributes = (raw) => {
        setNamesText(raw);
        formik.setFieldValue('variant_attributes', parseNames(raw));
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
                        value={namesText}
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
                                    {attributes.map((attribute) =>
                                        isColourName(attribute) ? (
                                            <div
                                                className="admin-variant-colour"
                                                key={attribute}
                                            >
                                                <FormInput
                                                    label={attribute}
                                                    value={
                                                        variant.options?.[
                                                            attribute
                                                        ] || ''
                                                    }
                                                    onChange={(e) =>
                                                        setColourValue(
                                                            variant,
                                                            attribute,
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder={
                                                        index === 0
                                                            ? 'e.g. Black'
                                                            : ''
                                                    }
                                                />
                                                {/* The colour shoppers see
                                                    as a round swatch. */}
                                                <Unlabelled>
                                                    <span
                                                        className={`admin-variant-swatch ${
                                                            variant.swatch
                                                                ? ''
                                                                : 'is-empty'
                                                        }`}
                                                        title="Pick the colour shoppers see"
                                                    >
                                                        <input
                                                            type="color"
                                                            aria-label={`${attribute} swatch`}
                                                            value={
                                                                variant.swatch ||
                                                                '#ffffff'
                                                            }
                                                            onChange={(e) =>
                                                                setSwatch(
                                                                    variant,
                                                                    attribute,
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </span>
                                                </Unlabelled>
                                            </div>
                                        ) : (
                                            <FormInput
                                                key={attribute}
                                                label={attribute}
                                                value={
                                                    variant.options?.[
                                                        attribute
                                                    ] || ''
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
                                                    index === 0
                                                        ? 'e.g. 32GB'
                                                        : ''
                                                }
                                            />
                                        ),
                                    )}
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

                                {/* The maker's part number for this option,
                                    which the shop page shows when it is
                                    chosen — StarTech swaps it the same way. */}
                                <FormInput
                                    label="MPN"
                                    value={variant.mpn || ''}
                                    onChange={(e) =>
                                        patchVariant(variant.key, {
                                            mpn: e.target.value,
                                        })
                                    }
                                    placeholder="Part no."
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

                                <Unlabelled>
                                    <button
                                        type="button"
                                        className="admin-receive-line-remove admin-variant-remove"
                                        aria-label="Remove this option"
                                        title={
                                            variant.stock_quantity > 0
                                                ? 'This option holds stock — it will be retired, not deleted'
                                                : 'Remove this option'
                                        }
                                        disabled={variants.length === 1}
                                        onClick={() =>
                                            setVariants(
                                                variants.filter(
                                                    (v) =>
                                                        v.key !== variant.key,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 size={15} />
                                    </button>
                                </Unlabelled>

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
