import React from 'react';
import Button from '@/Components/Button';
import Checkbox from '@/Components/Checkbox';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import Modal from '@/Components/Modal';
import { AlertTriangle } from 'lucide-react';
import Select from '@/Components/Select';
import { NAVBAR_BADGE_OPTIONS } from '@/constants';

/**
 * Reusable Add / Edit Category Form Modal
 */
/* Not an id, so it cannot collide with one. */
const NEW_BRAND = 'new';

/**
 * The shelves a category may sit under.
 *
 * Only level 1 and 2, because the tree is three deep — a parent at level 3
 * would make a level 4, which no screen draws and nothing on the server
 * refuses. The list arrives already filtered that way; this adds the top-level
 * choice and, when editing, takes the category itself out so it cannot be made
 * its own parent.
 *
 * @param {Array<{id: number, name: string}>} options
 * @param {number|undefined} editingId
 */
const parentChoicesFrom = (options, editingId) => [
    { value: '', label: 'None (Top-Level Root Category)' },
    ...options
        .filter((p) => !editingId || p.id !== editingId)
        .map((p) => ({ value: String(p.id), label: p.name })),
];

export const CategoryFormModal = ({
    modalState,
    onClose,
    formik,
    parentOptions = [],
    brandOptions = [],
    isSubmitting = false,
}) => {
    const trimmedName = (formik.values.name || '').trim();

    /*
     * The brand this shelf claims, when its own name is not that brand's.
     * Null when there is no brand, or when the two agree.
     */
    const brandMismatch = (() => {
        if (formik.values.create_brand || !formik.values.brand_id) return null;

        const brand = brandOptions.find(
            (b) => String(b.id) === String(formik.values.brand_id),
        );

        if (!brand || !trimmedName) return null;

        return brand.name.trim().toLowerCase() === trimmedName.toLowerCase()
            ? null
            : brand.name;
    })();

    const parentChoices = parentChoicesFrom(
        parentOptions,
        modalState.mode === 'create' ? undefined : modalState.category?.id,
    );

    return (
        <Modal
            isOpen={modalState.isOpen}
            onClose={onClose}
            title={
                modalState.mode === 'create'
                    ? modalState.defaultLevel === 1
                        ? 'Add New Root Category (Level 1)'
                        : modalState.defaultLevel === 2
                          ? `Add Subcategory under '${modalState.parentCategory?.name}' (Level 2)`
                          : `Add Item / Series under '${modalState.parentCategory?.name}' (Level 3)`
                    : `Edit Category: ${modalState.category?.name}`
            }
            maxWidth="560px"
        >
            <form onSubmit={formik.handleSubmit} noValidate>
                {/* Visual Target Hierarchy Banner */}
                {modalState.parentCategory && (
                    <div className="admin-summary-box flex items-center gap-2">
                        <span className="text-muted">Adding under:</span>
                        <strong>{modalState.parentCategory.name}</strong>
                        <span className="admin-cat-tree-level-tag ml-auto">
                            Level {modalState.defaultLevel}
                        </span>
                    </div>
                )}

                {/*
                    Searchable, like every other long list in the shop.

                    A native select holding 252 shelves is scrolled, not read,
                    and the names repeat — several are called Accessories — so
                    finding the right one meant counting down a list where the
                    right answer looks like three wrong ones. Select turns on
                    its own search above eight options.

                    Deliberately this list and not the category search the
                    product form uses: only a level 1 or 2 shelf may be a
                    parent, because the tree is three deep and nothing else
                    enforces that — the server accepts any parent_id that
                    exists. Searching all 1,390 here would quietly allow a
                    fourth level that no screen can draw.
                */}
                <Select
                    id="cat_parent_id"
                    name="parent_id"
                    label="Parent Category"
                    value={formik.values.parent_id || ''}
                    onChange={formik.handleChange}
                    options={parentChoices}
                    searchPlaceholder="Search shelves…"
                    className="mb-4"
                />

                {/*
                    A shelf can stand for a brand: "ASUS" under Brand PC is a
                    shelf with its own page, which is how the trade lists them.
                    Setting it here carries the brand's logo into the mega menu
                    and keeps the pair joined through a rename — they used to be
                    matched on their names alone.
                */}
                <FormSelect
                    id="cat_brand_id"
                    name="brand_id"
                    label="Stands for a Brand (Optional)"
                    value={
                        formik.values.create_brand
                            ? NEW_BRAND
                            : formik.values.brand_id || ''
                    }
                    onChange={(event) => {
                        const picked = event.target.value;
                        formik.setFieldValue(
                            'create_brand',
                            picked === NEW_BRAND,
                        );
                        formik.setFieldValue(
                            'brand_id',
                            picked === NEW_BRAND ? '' : picked,
                        );
                    }}
                    className="mb-4"
                >
                    <option value="">Not a brand shelf</option>
                    {/*
                        Offered here rather than sending the admin to the brands
                        screen and back. That trip is the one nobody makes: 386
                        shelves are named after makers with no brand row, so they
                        show no logo, no maker on the product page, and cannot be
                        filtered or featured.
                    */}
                    {trimmedName ? (
                        <option value={NEW_BRAND}>
                            ➕ Create “{trimmedName}” as a new brand
                        </option>
                    ) : null}
                    {brandOptions.map((b) => (
                        <option key={b.id} value={b.id}>
                            {b.name}
                        </option>
                    ))}
                </FormSelect>

                {/*
                    Said, not refused.
                    
                    A shelf that stands for a brand is normally named after it —
                    "Asus" under Gaming Laptop, "Samsung" under Phone. When the
                    two names disagree it is usually the wrong row being edited:
                    the level-3 shelves are drawn as small chips and the card
                    around them has the visible pencil, so "Headphone" got set to
                    stand for SteelSeries, which is not a thing that can be true.
                    
                    Not a rule, though. "Asus ROG" standing for Asus is a shelf
                    somebody may well want, and blocking it would be inventing a
                    constraint the shop does not have — so this asks rather than
                    decides.
                */}
                {brandMismatch && (
                    /* A status, not an alert: worth reading before saving,
                       never urgent enough to interrupt. */
                    <p className="admin-field-warning" role="status">
                        <AlertTriangle size={13} />
                        <span>
                            This shelf is called <b>{trimmedName}</b> but stands
                            for <b>{brandMismatch}</b>. That is right for a
                            shelf like &ldquo;ROG&rdquo; under Asus — but if you
                            meant to mark a maker, edit that maker&apos;s own
                            chip rather than the shelf holding it.
                        </span>
                    </p>
                )}

                {/* Category Name */}
                <FormInput
                    id="cat_name"
                    name="name"
                    label="Category Name"
                    value={formik.values.name}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    placeholder="e.g. Graphics Card (GPU) or NVIDIA RTX 4090"
                    error={formik.touched.name && formik.errors.name}
                />

                {/* Custom Slug */}
                <FormInput
                    id="cat_slug"
                    name="slug"
                    label="Custom URL Slug (Optional)"
                    value={formik.values.slug}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    placeholder="Leave empty to auto-generate from name"
                    error={formik.touched.slug && formik.errors.slug}
                />

                <div className="admin-form-row-2col">
                    {/* Icon Name */}
                    <FormInput
                        id="cat_icon"
                        name="icon"
                        label="Icon Identifier"
                        value={formik.values.icon}
                        onChange={formik.handleChange}
                        placeholder="e.g. Cpu, Monitor, Laptop"
                    />

                    {/* Badge Selector (for Root & Mega Menu) */}
                    <FormSelect
                        id="cat_badge"
                        name="badge"
                        label="Navbar Badge"
                        value={formik.values.badge}
                        onChange={formik.handleChange}
                        options={NAVBAR_BADGE_OPTIONS}
                    />
                </div>

                {/* Checkboxes for Is Offer & Is Active */}
                <div className="admin-form-checkbox-stack">
                    <Checkbox
                        name="is_offer"
                        label="Highlight as Special Offer / Deal link (Red text in Header)"
                        checked={formik.values.is_offer}
                        onChange={formik.handleChange}
                    />

                    <Checkbox
                        name="is_active"
                        label="Category is Active (Visible on Mega Menu & PLP)"
                        checked={formik.values.is_active}
                        onChange={formik.handleChange}
                    />
                </div>

                {/* Modal Footer Actions */}
                <div className="admin-modal-footer-btns">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={isSubmitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={isSubmitting}
                    >
                        {modalState.mode === 'create'
                            ? 'Create Category'
                            : 'Save Changes'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
};

export default CategoryFormModal;
