import React from 'react';
import { Modal, Button, FormInput, FormSelect, Checkbox } from '@/Components';
import { NAVBAR_BADGE_OPTIONS } from '@/constants';

/**
 * Reusable Add / Edit Category Form Modal
 */
export const CategoryFormModal = ({
    modalState,
    onClose,
    formik,
    parentOptions = [],
    isSubmitting = false,
}) => {
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
            <form onSubmit={formik.handleSubmit}>
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

                {/* Parent Selector */}
                <FormSelect
                    id="cat_parent_id"
                    name="parent_id"
                    label="Parent Category"
                    value={formik.values.parent_id || ''}
                    onChange={formik.handleChange}
                    className="mb-4"
                >
                    <option value="">None (Top-Level Root Category)</option>
                    {parentOptions
                        .filter(
                            (p) =>
                                modalState.mode === 'create' ||
                                p.id !== modalState.category?.id,
                        )
                        .map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                </FormSelect>

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
