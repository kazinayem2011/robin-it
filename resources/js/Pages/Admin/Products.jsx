import React, { useState, useMemo, useRef, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Package,
    Plus,
    Edit2,
    CheckCircle,
    XCircle,
    Crop,
    AlertTriangle,
} from 'lucide-react';
import Button from '@/Components/Button';
import Checkbox from '@/Components/Checkbox';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import ImageCropperModal from '@/Components/ImageCropperModal';
import Modal from '@/Components/Modal';
import ProductImage from '@/Components/ProductImage';
import { toast } from '@/Components/Toast';
import { adminProductSchema } from '@/validations';
import { adminService, uploadService } from '@/services';
import { formatBdt } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import VariantEditor from './Components/VariantEditor';
import SpecificationEditor from './Components/SpecificationEditor';
import { ROUTES } from '@/constants/endpoints';

/**
 * Shape the form values for the API.
 *
 * Blank numeric strings are dropped rather than sent as '', and `opening_stock`
 * is only included when a single product is actually being split into options —
 * it is an allocation of the existing shelf, never an instruction to add stock.
 */
const buildProductPayload = (values, editingProduct) => {
    const { variants, has_variants: hasVariants, ...rest } = values;

    // The stock field only exists when creating; an edit must never carry one.
    if (editingProduct) {
        delete rest.stock_quantity;
    }

    // Not a stock value — the level at which to buy more — so it is editable
    // at any time.
    rest.reorder_level =
        rest.reorder_level === '' || rest.reorder_level === null
            ? null
            : Number(rest.reorder_level);

    /*
     * A blank limit means no cap rather than zero, and a blank date means none
     * was promised. Sending '' would fail validation as a non-integer/non-date.
     */
    rest.allow_preorder = Boolean(rest.allow_preorder);
    rest.preorder_limit =
        rest.preorder_limit === '' || rest.preorder_limit === null
            ? null
            : Number(rest.preorder_limit);
    rest.preorder_release_at = rest.preorder_release_at || null;

    // Same trap as the pre-order fields: an untouched number input holds '',
    // which fails `nullable|integer`. Blank means the product has no warranty.
    rest.warranty_months =
        rest.warranty_months === '' || rest.warranty_months === null
            ? null
            : Number(rest.warranty_months);

    /*
     * `key` exists only so React can tell two half-typed rows apart; it is not
     * a column and has no meaning to the server. Rows still being filled in are
     * dropped here rather than sent to fail validation — the editor always
     * leaves a blank row at the bottom.
     */
    rest.specifications = (rest.specifications || [])
        .filter((spec) => spec.name?.trim() && spec.value?.trim())
        .map((spec) => ({
            group: spec.group?.trim() || null,
            name: spec.name.trim(),
            value: spec.value.trim(),
        }));

    if (!hasVariants) {
        return { ...rest, has_variants: false };
    }

    const isConverting =
        Boolean(editingProduct) && !editingProduct.has_variants;

    return {
        ...rest,
        has_variants: true,
        variants: (variants || []).map((variant) => {
            const line = {
                id: variant.id || undefined,
                options: variant.options || {},
                sku: variant.sku || null,
                image_url: variant.image_url || null,
                reorder_level:
                    variant.reorder_level === '' ||
                    variant.reorder_level === undefined
                        ? null
                        : Number(variant.reorder_level),
                price: variant.price === '' ? null : Number(variant.price),
                discount_price:
                    variant.discount_price === ''
                        ? null
                        : Number(variant.discount_price),
                is_active: variant.is_active !== false,
            };

            if (isConverting) {
                line.opening_stock = Number(variant.opening_stock) || 0;
            }

            return line;
        }),
    };
};

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
    // The cropper is shared between the product shot and each option's own
    // shot, so it has to remember which one it was opened for.
    const [cropTarget, setCropTarget] = useState('product');
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
            warranty_months: '',
            specifications: [],
            image_path: '/images/product_cpu_i9.jpg',
            is_featured: false,
            is_active: true,
            reorder_level: '',
            barcode: '',
            allow_preorder: false,
            preorder_limit: '',
            preorder_release_at: '',
            has_variants: false,
            variant_attributes: [],
            variants: [],
        },
        validationSchema: adminProductSchema,
        // No enableReinitialize here: `initialValues` is a blank literal that is
        // rebuilt on every render, so Formik would keep resetting the form back
        // to it and wipe the values handleOpenEdit had just loaded. Editing a
        // record opened a completely empty form because of that.
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                const payload = buildProductPayload(values, editingProduct);

                if (editingProduct) {
                    await adminService.updateProduct(
                        editingProduct.id,
                        payload,
                    );
                    toast.success(
                        `Product "${values.name}" updated successfully!`,
                        'Product Updated',
                    );
                } else {
                    await adminService.createProduct(payload);
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

    /*
     * Typing "keyboard" used to fire eight full page requests, one per
     * keystroke, each replacing the last — the results flickered through
     * "k", "ke", "key" on the way to the answer, and on a slow connection
     * they could land out of order and leave the wrong list on screen.
     *
     * The input stays instant; only the request waits.
     */
    /*
     * The category list is a flat array of several hundred rows, and the select
     * showed nothing but `cat.name`. Once the tree went three levels deep that
     * became unusable: "Type-C Cable" appears under both Mobile Accessories and
     * Cable, "Car Charger" under both Mobile Accessories and Gadget, and the
     * dropdown offered no way to tell which was which.
     *
     * Grouped by top-level category, indented by depth, so a name is read in
     * the context that gives it meaning.
     */
    const categoryOptionGroups = useMemo(() => {
        const byParent = new Map();

        categories.forEach((cat) => {
            const key = cat.parent_id ?? 'root';
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key).push(cat);
        });

        const branch = (cat, depth) => [
            {
                id: cat.id,
                // Non-breaking spaces: a <option> collapses ordinary ones, so
                // regular indentation simply does not render.
                label: `${'   '.repeat(depth)}${depth ? '└ ' : ''}${cat.name}`,
            },
            ...(byParent.get(cat.id) ?? []).flatMap((child) =>
                branch(child, depth + 1),
            ),
        ];

        return (byParent.get('root') ?? []).map((root) => ({
            id: root.id,
            name: root.name,
            options: branch(root, 0),
        }));
    }, [categories]);

    const searchTimer = useRef(null);

    useEffect(() => () => clearTimeout(searchTimer.current), []);

    const handleSearch = (term) => {
        setSearchTerm(term);

        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => {
            router.get(
                ROUTES.ADMIN_PRODUCTS,
                {
                    search: term,
                    category_id: selectedCategory,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 350);
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
                warranty_months: '',
                specifications: [],
                image_path: '/images/product_cpu_i9.jpg',
                is_featured: false,
                is_active: true,
                reorder_level: '',
                allow_preorder: false,
                preorder_limit: '',
                preorder_release_at: '',
                has_variants: false,
                variant_attributes: [],
                variants: [],
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
                warranty_months: p.warranty_months ?? '',
                // Server rows have no `key`; the editor needs one that survives
                // re-renders, so give each an identity as it is loaded in.
                specifications: (p.specifications || []).map((spec, i) => ({
                    key: `spec-${spec.id ?? i}`,
                    group: spec.group || '',
                    name: spec.name || '',
                    value: spec.value || '',
                })),
                image_path: p.images?.[0]?.image_path || '',
                is_featured: Boolean(p.is_featured),
                is_active: Boolean(p.is_active),
                reorder_level: p.reorder_level ?? '',
                barcode: p.barcode ?? '',
                allow_preorder: Boolean(p.allow_preorder),
                preorder_limit: p.preorder_limit ?? '',
                preorder_release_at: p.preorder_release_at
                    ? String(p.preorder_release_at).slice(0, 10)
                    : '',
                has_variants: Boolean(p.has_variants),
                variant_attributes: p.variant_attributes || [],
                variants: (p.variants || [])
                    .filter((v) => v.is_active)
                    .map((v) => ({
                        key: `v-${v.id}`,
                        id: v.id,
                        options: v.options || {},
                        sku: v.sku || '',
                        image_url: v.image_url || '',
                        reorder_level: v.reorder_level ?? '',
                        price: v.price ?? '',
                        discount_price: v.discount_price ?? '',
                        opening_stock: '',
                        is_active: Boolean(v.is_active),
                        // Read-only here: editing an option never moves stock.
                        stock_quantity: v.stock_quantity ?? 0,
                    })),
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
                        {/*
                         * The PC Builder reads these specs to check whether
                         * parts fit. A missing one is treated as "unknown"
                         * rather than a failure, so without saying so here
                         * the shop cannot tell a checked build from an
                         * unchecked one.
                         */}
                        {p.missing_specs?.length > 0 && (
                            <span
                                className="admin-spec-gap"
                                title="The PC Builder cannot check compatibility without these"
                            >
                                <AlertTriangle size={12} />
                                Add spec: {p.missing_specs.join(', ')}
                            </span>
                        )}
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
                    {/*
                     * There used to be a "+5 Stock" button here that added five
                     * units with no supplier, no cost and no record of who did
                     * it. Restocking now goes through a delivery.
                     */}
                    <Link
                        href={ROUTES.ADMIN_STOCK}
                        className="btn btn-secondary btn-sm admin-btn-quick-restock"
                        title="Record a delivery for this product"
                        onClick={(e) => e.stopPropagation()}
                    >
                        Receive
                    </Link>
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

            if (cropTarget === 'product') {
                formik.setFieldValue('image_path', path);
                toast.success('Product image uploaded.', 'Upload Complete');
            } else {
                // cropTarget is the option's row key.
                formik.setFieldValue(
                    'variants',
                    (formik.values.variants || []).map((v) =>
                        v.key === cropTarget ? { ...v, image_url: path } : v,
                    ),
                );
                toast.success('Option image uploaded.', 'Upload Complete');
            }
        } catch (err) {
            toast.error(
                err?.message || 'Could not upload that image.',
                'Upload Failed',
            );
        } finally {
            setUploadingImage(false);
            setCropTarget('product');
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
                <form onSubmit={formik.handleSubmit} noValidate>
                    <FormInput
                        id="name"
                        name="name"
                        required
                        label="Product Title"
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
                        >
                            {categoryOptionGroups.map((group) => (
                                <optgroup key={group.id} label={group.name}>
                                    {group.options.map((opt) => (
                                        <option key={opt.id} value={opt.id}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </FormSelect>
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
                            required
                            label="Regular Price (BDT)"
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
                        {/*
                         * Stock is only typeable once, when the product is
                         * first entered. After that it moves through
                         * deliveries, orders and recorded adjustments — an
                         * editable field here let a stale form put already-sold
                         * units back on the shelf.
                         */}
                        <FormInput
                            id="reorder_level"
                            name="reorder_level"
                            label="Reorder at"
                            type="number"
                            min="0"
                            value={formik.values.reorder_level}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Store default"
                            helperText="Flag this for reordering once stock falls to here."
                        />

                        {/*
                         * The number on the box. A scanner types it at a stock
                         * take or a delivery, which is what stops counting
                         * meaning finding each product in a list by name.
                         */}
                        <FormInput
                            id="barcode"
                            name="barcode"
                            label="Barcode"
                            value={formik.values.barcode}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Scan or type it"
                            error={
                                formik.touched.barcode && formik.errors.barcode
                            }
                            helperText="The manufacturer's number, for scanning at a count. Leave blank if there is none."
                        />

                        {/*
                         * Pre-order. Selling past zero takes the balance
                         * negative, which is what "units owed" looks like in
                         * the ledger, so it stays off unless someone turns it
                         * on for this product.
                         */}
                        <div className="auth-form-group">
                            <Checkbox
                                id="allow_preorder"
                                name="allow_preorder"
                                label="Allow pre-order when out of stock"
                                checked={formik.values.allow_preorder}
                                onChange={formik.handleChange}
                            />
                            <p className="admin-field-hint">
                                Customers can buy this with an empty shelf. The
                                balance goes negative by the number of units
                                owed, and the next delivery clears it.
                            </p>
                        </div>

                        {formik.values.allow_preorder && (
                            <>
                                <FormInput
                                    id="preorder_limit"
                                    name="preorder_limit"
                                    label="Pre-order limit"
                                    type="number"
                                    min="1"
                                    value={formik.values.preorder_limit}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    placeholder="No limit"
                                    helperText="Most units sellable beyond the shelf. Blank means no cap — worth setting, or one scripted buyer can commit you to any number."
                                />

                                <FormInput
                                    id="preorder_release_at"
                                    name="preorder_release_at"
                                    label="Expected in stock"
                                    type="date"
                                    value={formik.values.preorder_release_at}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    helperText="Shown to the customer. A pre-order without a date is a delay they did not agree to."
                                />
                            </>
                        )}

                        {editingProduct ? (
                            <div className="auth-form-group">
                                <label className="auth-label">Stock</label>
                                <div className="admin-stock-readonly">
                                    <span className="admin-stock-readonly-qty">
                                        {editingProduct.has_variants
                                            ? `${editingProduct.stock_quantity} across ${
                                                  (
                                                      editingProduct.variants ||
                                                      []
                                                  ).filter((v) => v.is_active)
                                                      .length
                                              } option(s)`
                                            : `${editingProduct.stock_quantity} on hand`}
                                    </span>
                                    <Link
                                        href={ROUTES.ADMIN_STOCK}
                                        className="admin-stock-readonly-link"
                                    >
                                        Receive or adjust
                                    </Link>
                                </div>
                                <span className="admin-field-hint">
                                    Changed by deliveries, orders and recorded
                                    adjustments — never edited here.
                                </span>
                            </div>
                        ) : (
                            <FormInput
                                id="stock_quantity"
                                name="stock_quantity"
                                required
                                label="Opening stock"
                                type="number"
                                value={formik.values.stock_quantity}
                                onChange={formik.handleChange}
                                onBlur={formik.handleBlur}
                                error={
                                    formik.touched.stock_quantity &&
                                    formik.errors.stock_quantity
                                }
                                helperText="Units already on the shelf. Later arrivals are recorded as deliveries."
                            />
                        )}
                    </div>

                    <VariantEditor
                        formik={formik}
                        editingProduct={editingProduct}
                        onPickImage={(variantKey) => {
                            setCropTarget(variantKey);
                            setCropperOpen(true);
                        }}
                        uploading={uploadingImage}
                    />

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

                    {/* The column has existed since the first migration and the
                        product page has always rendered it, but the form had no
                        field — so every description on the site came from a
                        seeder and no admin could write one. */}
                    <FormInput
                        id="description"
                        name="description"
                        type="textarea"
                        rows={8}
                        label="Full Description"
                        value={formik.values.description}
                        onChange={formik.handleChange}
                        onBlur={formik.handleBlur}
                        error={
                            formik.touched.description &&
                            formik.errors.description
                        }
                        placeholder="What the product is, who it suits, what is in the box. Basic HTML is allowed."
                        helperText="Shown under the Description tab on the product page."
                    />

                    <FormInput
                        id="warranty_months"
                        name="warranty_months"
                        type="number"
                        label="Warranty (months)"
                        value={formik.values.warranty_months}
                        onChange={formik.handleChange}
                        onBlur={formik.handleBlur}
                        error={
                            formik.touched.warranty_months &&
                            formik.errors.warranty_months
                        }
                        placeholder="24"
                        helperText="Counted from the day the customer buys it. Leave blank if the product has none."
                    />

                    <SpecificationEditor formik={formik} />

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
                                onClick={() => {
                                    setCropTarget('product');
                                    setCropperOpen(true);
                                }}
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
