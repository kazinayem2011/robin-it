import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { Package, Plus, Edit2, CheckCircle, XCircle, Crop } from 'lucide-react';
import {
    Modal,
    Button,
    FormInput,
    FormSelect,
    Checkbox,
    DataTable,
    ProductImage,
    toast,
    ImageCropperModal,
} from '@/Components';
import { adminProductSchema } from '@/validations';
import { adminService, uploadService } from '@/services';
import { formatBdt } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';

export default function Products({
    products = { data: [] },
    categories = [],
    brands = [],
    selectedCategory: initialCategory = '',
    search = '',
}) {
    const [searchTerm, setSearchTerm] = useState(search);
    const [selectedCategory, setSelectedCategory] = useState(initialCategory);
    const [modalOpen, setModalOpen] = useState(false);
    const [cropperOpen, setCropperOpen] = useState(false);
    const [uploadingImage, setUploadingImage] = useState(false);
    const [editingProduct, setEditingProduct] = useState(null);

    // Unified Product Form (Formik + Yup)
    const formik = useFormik({
        initialValues: {
            name: '',
            category_id: categories[0]?.id || '',
            brand_id: brands[0]?.id || '',
            price: '',
            discount_price: '',
            stock_quantity: 10,
            short_description: '',
            description: '',
            image_path: '/images/product_cpu_i9.jpg',
            is_featured: false,
            is_active: true,
        },
        validationSchema: adminProductSchema,
        enableReinitialize: true,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editingProduct) {
                    await adminService.updateProduct(editingProduct.id, values);
                    toast.success(
                        `Product "${values.name}" updated successfully!`,
                        'Product Updated',
                    );
                } else {
                    await adminService.createProduct(values);
                    toast.success(
                        `Product "${values.name}" added to catalog successfully!`,
                        'Product Created',
                    );
                }
                setModalOpen(false);
                setEditingProduct(null);
                resetForm();
                router.reload({ preserveScroll: true });
            } catch (error) {
                console.error('Failed to save product', error);
                toast.error(
                    error?.message ||
                        'Failed to save product. Please check values.',
                    'Save Error',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleSearch = (term) => {
        setSearchTerm(term);
        router.get(
            ROUTES.ADMIN_PRODUCTS,
            {
                search: term,
                category_id: selectedCategory,
            },
            {
                preserveState: true,
            },
        );
    };

    const handleCategoryFilter = (catId) => {
        setSelectedCategory(catId);
        router.get(
            ROUTES.ADMIN_PRODUCTS,
            {
                search: searchTerm,
                category_id: catId,
            },
            {
                preserveState: true,
            },
        );
    };

    const handleOpenCreate = () => {
        setEditingProduct(null);
        formik.resetForm({
            values: {
                name: '',
                category_id: categories[0]?.id || '',
                brand_id: brands[0]?.id || '',
                price: '',
                discount_price: '',
                stock_quantity: 10,
                short_description: '',
                description: '',
                image_path: '/images/product_cpu_i9.jpg',
                is_featured: false,
                is_active: true,
            },
        });
        setModalOpen(true);
    };

    const handleOpenEdit = (p) => {
        setEditingProduct(p);
        formik.resetForm({
            values: {
                name: p.name || '',
                category_id: p.category_id || categories[0]?.id || '',
                brand_id: p.brand_id || brands[0]?.id || '',
                price: p.price || '',
                discount_price: p.discount_price || '',
                stock_quantity: p.stock_quantity ?? 0,
                short_description: p.short_description || '',
                description: p.description || '',
                image_path: p.images?.[0]?.image_path || '',
                is_featured: Boolean(p.is_featured),
                is_active: Boolean(p.is_active),
            },
        });
        setModalOpen(true);
    };

    const columns = [
        {
            key: 'details',
            header: 'Product Details',
            render: (p) => (
                <div className="admin-product-item-flex">
                    <ProductImage
                        product={p}
                        alt={p.name}
                        className="admin-product-item-thumb"
                    />
                    <div>
                        <strong className="admin-product-item-title">
                            {p.name}
                        </strong>
                        <span className="admin-product-item-sku">
                            SKU: {p.sku || `PROD-${p.id}`}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'category',
            header: 'Category',
            render: (p) => (
                <span className="admin-table-item-title">
                    {p.category?.name || 'Hardware'}
                </span>
            ),
        },
        {
            key: 'brand',
            header: 'Brand',
            render: (p) => (
                <span className="admin-table-item-title">
                    {p.brand?.name || 'Standard'}
                </span>
            ),
        },
        {
            key: 'price',
            header: 'Price (BDT)',
            render: (p) => (
                <div>
                    <strong className="admin-table-price-strong">
                        {formatBdt(p.price)}
                    </strong>
                    {p.discount_price && (
                        <span className="admin-product-special-price">
                            {formatBdt(p.discount_price)}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'stock',
            header: 'Stock Status',
            render: (p) => (
                <div className="admin-input-row-flex">
                    <span
                        className={`admin-badge-stock ${
                            p.stock_quantity <= 5
                                ? 'admin-badge-stock-danger'
                                : 'admin-badge-stock-ok'
                        }`}
                    >
                        {p.stock_quantity <= 5 && '⚠️ '}
                        {p.stock_quantity} in Stock
                    </span>
                    <button
                        type="button"
                        className="btn btn-secondary btn-sm admin-btn-quick-restock"
                        title="Quick add +5 inventory units"
                        onClick={async (e) => {
                            e.stopPropagation();
                            try {
                                await adminService.updateProduct(p.id, {
                                    stock_quantity: p.stock_quantity + 5,
                                    price: p.price,
                                    is_active: p.is_active,
                                });
                                toast.success(
                                    `Restocked +5 units to ${p.name}!`,
                                );
                                router.reload({ preserveScroll: true });
                            } catch (err) {
                                toast.error('Failed to quick restock product.');
                            }
                        }}
                    >
                        +5 Stock
                    </button>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Visibility',
            render: (p) =>
                p.is_active ? (
                    <span className="admin-product-status-active">
                        <CheckCircle size={14} /> Active
                    </span>
                ) : (
                    <span className="admin-product-status-inactive">
                        <XCircle size={14} /> Inactive
                    </span>
                ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (p) => (
                <button
                    type="button"
                    className="admin-table-icon-btn"
                    onClick={() => handleOpenEdit(p)}
                    title="Edit Product"
                >
                    <Edit2 size={14} />
                </button>
            ),
        },
    ];

    const handleCropComplete = async ({ file }) => {
        setCropperOpen(false);
        setUploadingImage(true);
        try {
            const { path } = await uploadService.uploadImage(file, 'products');
            formik.setFieldValue('image_path', path);
            toast.success('Product image uploaded.', 'Upload Complete');
        } catch (err) {
            toast.error(
                err?.message || 'Could not upload that image.',
                'Upload Failed',
            );
        } finally {
            setUploadingImage(false);
        }
    };

    return (
        <AdminLayout
            title="Products & Inventory"
            subtitle={`Manage ${siteConfig.name} Hardware Catalog, Live Stock Levels & Pricing`}
        >
            <Head title={`Admin Products & Inventory — ${siteConfig.name}`} />

            {/* Reusable Data Table */}
            <DataTable
                columns={columns}
                data={products}
                keyField="id"
                title="Product Catalog"
                subtitle="All listed hardware inventory items and real-time stock"
                searchable
                searchValue={searchTerm}
                onSearch={handleSearch}
                searchPlaceholder="Search by name, SKU..."
                emptyIcon={Package}
                emptyTitle="No Products Found"
                emptyDescription="Try adjusting your search keyword or selected category filter."
                headerActions={
                    <>
                        <select
                            value={selectedCategory}
                            onChange={(e) =>
                                handleCategoryFilter(e.target.value)
                            }
                            className="admin-select-input"
                        >
                            <option value="">All Categories</option>
                            {categories.map((cat) => (
                                <option key={cat.id} value={cat.id}>
                                    {cat.name}
                                </option>
                            ))}
                        </select>

                        <Button
                            variant="primary"
                            size="sm"
                            icon={Plus}
                            onClick={handleOpenCreate}
                        >
                            Add Product
                        </Button>
                    </>
                }
            />

            {/* Single Unified Product Modal (Create & Edit SSOT) */}
            <Modal
                isOpen={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    setEditingProduct(null);
                }}
                title={
                    editingProduct
                        ? `Edit Product: ${editingProduct.name}`
                        : 'Add New Technology Product'
                }
                maxWidth="640px"
            >
                <form onSubmit={formik.handleSubmit}>
                    <FormInput
                        id="name"
                        name="name"
                        label="Product Title *"
                        value={formik.values.name}
                        onChange={formik.handleChange}
                        onBlur={formik.handleBlur}
                        error={formik.touched.name && formik.errors.name}
                        placeholder="e.g. Intel Core i7-14700K 20-Core Processor"
                    />

                    <div className="admin-modal-form-grid">
                        <FormSelect
                            label="Category"
                            name="category_id"
                            required
                            formik={formik}
                            options={categories.map((cat) => ({
                                value: cat.id,
                                label: cat.name,
                            }))}
                        />
                        <FormSelect
                            label="Brand"
                            name="brand_id"
                            formik={formik}
                            placeholder="No Brand / Generic"
                            options={brands.map((b) => ({
                                value: b.id,
                                label: b.name,
                            }))}
                        />
                    </div>

                    <div className="admin-form-grid-3">
                        <FormInput
                            id="price"
                            name="price"
                            label="Regular Price (BDT) *"
                            type="number"
                            value={formik.values.price}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={formik.touched.price && formik.errors.price}
                            placeholder="e.g. 45000"
                        />
                        <FormInput
                            id="discount_price"
                            name="discount_price"
                            label="Special Discount Price"
                            type="number"
                            value={formik.values.discount_price}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.discount_price &&
                                formik.errors.discount_price
                            }
                            placeholder="Optional"
                        />
                        <FormInput
                            id="stock_quantity"
                            name="stock_quantity"
                            label="Stock Quantity (Units) *"
                            type="number"
                            value={formik.values.stock_quantity}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.stock_quantity &&
                                formik.errors.stock_quantity
                            }
                        />
                    </div>

                    <FormInput
                        id="short_description"
                        name="short_description"
                        label="Short Summary / Key Highlights"
                        value={formik.values.short_description}
                        onChange={formik.handleChange}
                        onBlur={formik.handleBlur}
                        error={
                            formik.touched.short_description &&
                            formik.errors.short_description
                        }
                        placeholder="e.g. 20 Cores (8P + 12E), up to 5.6 GHz, LGA1700 Socket"
                    />

                    {/* Product image. The form carried an image_path value with no
                        field to edit it, so every product kept the same stock photo. */}
                    <div className="admin-image-field">
                        <FormInput
                            id="image_path"
                            name="image_path"
                            label="Product Image"
                            value={formik.values.image_path}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.image_path &&
                                formik.errors.image_path
                            }
                            placeholder="/images/product.jpg or upload below"
                        />
                        <div className="admin-image-field-actions">
                            {formik.values.image_path && (
                                <img
                                    src={formik.values.image_path}
                                    alt="Product preview"
                                    className="admin-image-preview"
                                />
                            )}
                            <Button
                                type="button"
                                variant="secondary"
                                icon={Crop}
                                loading={uploadingImage}
                                disabled={uploadingImage}
                                onClick={() => setCropperOpen(true)}
                            >
                                {uploadingImage
                                    ? 'Uploading…'
                                    : 'Crop / Upload'}
                            </Button>
                        </div>
                    </div>

                    <div className="admin-form-checkbox-row">
                        <Checkbox
                            name="is_active"
                            label="Active in Live Storefront"
                            checked={formik.values.is_active}
                            onChange={formik.handleChange}
                        />
                        <Checkbox
                            name="is_featured"
                            label="Featured Deal (Show on Homepage)"
                            checked={formik.values.is_featured}
                            onChange={formik.handleChange}
                        />
                    </div>

                    <div className="admin-modal-footer-btns">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setModalOpen(false);
                                setEditingProduct(null);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            loading={formik.isSubmitting}
                        >
                            {editingProduct
                                ? 'Update Product'
                                : 'Create Product'}
                        </Button>
                    </div>
                </form>
            </Modal>

            {cropperOpen && (
                <ImageCropperModal
                    isOpen={cropperOpen}
                    onClose={() => setCropperOpen(false)}
                    onCropComplete={handleCropComplete}
                    aspectRatio={1}
                    title="Crop Product Image (1:1)"
                />
            )}
        </AdminLayout>
    );
}
