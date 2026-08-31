import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Package,
    Plus,
    Edit2,
    Eye,
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
import ProductDetailsModal from './Components/ProductDetailsModal';
import CategoryPicker from '@/Components/CategoryPicker';
import RichTextEditor from '@/Components/RichTextEditor';
import { bulletsToLines, linesToBullets } from '@/utils/bulletHtml';
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
    // The primary is added back server-side regardless, so it is dropped here
    // rather than sent as a duplicate.
    rest.category_ids = (rest.category_ids || [])
        .map(Number)
        .filter((id) => id && id !== Number(rest.category_id));

    rest.key_features = linesToBullets(rest.key_features);

    rest.checkout_discount =
        rest.checkout_discount === '' || rest.checkout_discount === null
            ? null
            : Number(rest.checkout_discount);
    rest.emi_available = Boolean(rest.emi_available);
    rest.emi_max_months =
        rest.emi_max_months === '' || rest.emi_max_months === null
            ? null
            : Number(rest.emi_max_months);
    rest.related_product_ids = (rest.related_product_ids || []).map(Number);

    // Empty dates mean "no schedule", which is not the same as an empty string
    // — `nullable|date` rejects ''.
    rest.discount_starts_at = rest.discount_starts_at || null;
    rest.discount_ends_at = rest.discount_ends_at || null;
    rest.min_order_quantity = Number(rest.min_order_quantity) || 1;

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
    // The read-only panel. Holds an id rather than the row, because it
    // fetches the full record — the table row is a thin projection.
    const [detailsId, setDetailsId] = useState(null);
    // Names for the extra-category chips. The ids live in Formik; these are
    // only what the chips display, and come from whatever was just picked or
    // from the product being edited.
    const [extraCategoryChips, setExtraCategoryChips] = useState([]);

    // Unified Product Form (Formik + Yup)
    const formik = useFormik({
        initialValues: {
            name: '',
            category_id: '',
            brand_id: brands[0]?.id || '',
            price: '',
            discount_price: '',
            stock_quantity: 0,
            short_description: '',
            description: '',
            warranty_months: '',
            category_ids: [],
            model: '',
            mpn: '',
            warranty_text: '',
            key_features: '',
            checkout_discount: '',
            discount_starts_at: '',
            discount_ends_at: '',
            min_order_quantity: 1,
            emi_available: false,
            emi_max_months: '',
            out_of_stock_status: '',
            related_product_ids: [],
            meta_title: '',
            meta_description: '',
            meta_keyword: '',
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
                category_id: '',
                brand_id: brands[0]?.id || '',
                price: '',
                discount_price: '',
                stock_quantity: 0,
                short_description: '',
                description: '',
                warranty_months: '',
                category_ids: [],
                model: '',
                mpn: '',
                warranty_text: '',
                key_features: '',
                checkout_discount: '',
                discount_starts_at: '',
                discount_ends_at: '',
                min_order_quantity: 1,
                emi_available: false,
                emi_max_months: '',
                out_of_stock_status: '',
                related_product_ids: [],
                meta_title: '',
                meta_description: '',
                meta_keyword: '',
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
                category_id: p.category_id || '',
                brand_id: p.brand_id || brands[0]?.id || '',
                price: p.price || '',
                discount_price: p.discount_price || '',
                stock_quantity: p.stock_quantity ?? 0,
                short_description: p.short_description || '',
                description: p.description || '',
                warranty_months: p.warranty_months ?? '',
                model: p.model ?? '',
                mpn: p.mpn ?? '',
                warranty_text: p.warranty_text ?? '',
                key_features: bulletsToLines(p.key_features),
                discount_starts_at: p.discount_starts_at
                    ? String(p.discount_starts_at).slice(0, 10)
                    : '',
                discount_ends_at: p.discount_ends_at
                    ? String(p.discount_ends_at).slice(0, 10)
                    : '',
                min_order_quantity: p.min_order_quantity ?? 1,
                checkout_discount: p.checkout_discount ?? '',
                emi_available: Boolean(p.emi_available),
                emi_max_months: p.emi_max_months ?? '',
                out_of_stock_status: p.out_of_stock_status ?? '',
                related_product_ids: (p.related_products || []).map(
                    (r) => r.id,
                ),
                category_ids: (p.categories || []).map((c) => c.id),
                meta_title: p.meta_title || '',
                meta_description: p.meta_description || '',
                meta_keyword: p.meta_keyword || '',
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
                <div className="admin-table-icon-group">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        onClick={() => setDetailsId(p.id)}
                        title="View full details"
                        aria-label={`View details for ${p.name}`}
                    >
                        <Eye size={14} />
                    </button>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        onClick={() => handleOpenEdit(p)}
                        title="Edit Product"
                        aria-label={`Edit ${p.name}`}
                    >
                        <Edit2 size={14} />
                    </button>
                </div>
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
                        <div className="admin-filter-picker">
                            <CategoryPicker
                                label=""
                                placeholder="Filter by category…"
                                value={selectedCategory}
                                onChange={(id) =>
                                    handleCategoryFilter(id || '')
                                }
                            />
                        </div>

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
                maxWidth="860px"
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
                        <CategoryPicker
                            label="Category"
                            required
                            value={formik.values.category_id}
                            initialLabel={editingProduct?.category?.name || ''}
                            onChange={(id) =>
                                formik.setFieldValue('category_id', id)
                            }
                            error={
                                formik.touched.category_id &&
                                formik.errors.category_id
                            }
                            helperText="Where the product lives. Type a few letters."
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

                    <div className="admin-modal-form-grid">
                        <FormInput
                            id="model"
                            name="model"
                            label="Model"
                            value={formik.values.model}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={formik.touched.model && formik.errors.model}
                            placeholder="Cyborg 15 Black Edition A13UC"
                            helperText="What a customer says at the counter."
                        />
                        <FormInput
                            id="mpn"
                            name="mpn"
                            label="MPN"
                            value={formik.values.mpn}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={formik.touched.mpn && formik.errors.mpn}
                            placeholder="9S7-15K112-2423"
                            helperText="Manufacturer part number. Not the barcode."
                        />
                    </div>

                    {/* A product belongs in more than one place: an Asus gaming
                        laptop sits under both "Gaming Laptop > Asus" and "All
                        Laptop > Asus". The primary above still gives it its
                        breadcrumb and canonical URL. */}
                    <CategoryPicker
                        label="Also list under"
                        multiple
                        placeholder="Search to add another category…"
                        chips={extraCategoryChips}
                        onRemove={(id) => {
                            setExtraCategoryChips((c) =>
                                c.filter((x) => x.id !== id),
                            );
                            formik.setFieldValue(
                                'category_ids',
                                (formik.values.category_ids || []).filter(
                                    (x) => x !== id,
                                ),
                            );
                        }}
                        onChange={(category) => {
                            if (
                                (formik.values.category_ids || []).includes(
                                    category.id,
                                )
                            ) {
                                return;
                            }
                            setExtraCategoryChips((c) => [...c, category]);
                            formik.setFieldValue('category_ids', [
                                ...(formik.values.category_ids || []),
                                category.id,
                            ]);
                        }}
                    />

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
                                label="Opening stock"
                                type="number"
                                value={formik.values.stock_quantity}
                                onChange={formik.handleChange}
                                onBlur={formik.handleBlur}
                                error={
                                    formik.touched.stock_quantity &&
                                    formik.errors.stock_quantity
                                }
                                helperText="Only stock already on the shelf. Leave at 0 if it is arriving on a purchase order — deliveries are recorded under Purchasing."
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
                    <RichTextEditor
                        id="description"
                        label="Full Description"
                        value={formik.values.description}
                        onChange={(html) =>
                            formik.setFieldValue('description', html)
                        }
                        error={
                            formik.touched.description &&
                            formik.errors.description
                        }
                        placeholder="What the product is, who it suits, what is in the box."
                        helperText="Shown under the Description tab. Formatting here appears on the product page."
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

                    <FormInput
                        id="warranty_text"
                        name="warranty_text"
                        label="Warranty Terms"
                        value={formik.values.warranty_text}
                        onChange={formik.handleChange}
                        onBlur={formik.handleBlur}
                        error={
                            formik.touched.warranty_text &&
                            formik.errors.warranty_text
                        }
                        placeholder="2 Years warranty (Battery & Adapter 1 Year)"
                        helperText="What the customer is told. The months above are what the claims system counts."
                    />

                    {/* One feature per line. The field is stored as markup —
                        the product page renders it as a list — but that is no
                        reason to make a shopkeeper type <ul><li>, which the
                        placeholder used to ask them to do. */}
                    <FormInput
                        id="key_features"
                        name="key_features"
                        type="textarea"
                        rows={6}
                        label="Key Features"
                        value={formik.values.key_features}
                        onChange={formik.handleChange}
                        onBlur={formik.handleBlur}
                        error={
                            formik.touched.key_features &&
                            formik.errors.key_features
                        }
                        placeholder={
                            'Processor: Intel Core i5-13420H\n' +
                            'RAM: 16GB DDR5 5200MHz\n' +
                            'Graphics: NVIDIA RTX 3050 4GB'
                        }
                        helperText="One feature per line. Shown as a bulleted list at the top of the product page."
                    />

                    {/* A sale that stops on time whether or not anyone is at a
                        desk. Blank dates mean "until changed", which is what
                        every discount was before this existed. */}
                    <div className="admin-form-grid-3">
                        <FormInput
                            id="discount_starts_at"
                            name="discount_starts_at"
                            type="date"
                            label="Discount Starts"
                            value={formik.values.discount_starts_at}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.discount_starts_at &&
                                formik.errors.discount_starts_at
                            }
                            helperText="Blank starts immediately."
                        />
                        <FormInput
                            id="discount_ends_at"
                            name="discount_ends_at"
                            type="date"
                            label="Discount Ends"
                            value={formik.values.discount_ends_at}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.discount_ends_at &&
                                formik.errors.discount_ends_at
                            }
                            helperText="Blank runs until you change it."
                        />
                        <FormInput
                            id="min_order_quantity"
                            name="min_order_quantity"
                            type="number"
                            label="Minimum Order Qty"
                            value={formik.values.min_order_quantity}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.min_order_quantity &&
                                formik.errors.min_order_quantity
                            }
                            helperText="For things not sold singly."
                        />
                    </div>

                    <div className="admin-form-grid-3">
                        <FormInput
                            id="checkout_discount"
                            name="checkout_discount"
                            type="number"
                            label="Checkout Discount (BDT)"
                            value={formik.values.checkout_discount}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.checkout_discount &&
                                formik.errors.checkout_discount
                            }
                            placeholder="1500"
                            helperText="Only for paying at once. Not given to EMI buyers."
                        />
                        <FormInput
                            id="emi_max_months"
                            name="emi_max_months"
                            type="number"
                            label="EMI Months"
                            value={formik.values.emi_max_months}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.emi_max_months &&
                                formik.errors.emi_max_months
                            }
                            placeholder="12"
                            helperText="Instalment is the regular price divided by this."
                        />
                        <FormInput
                            id="out_of_stock_status"
                            name="out_of_stock_status"
                            label="When Out of Stock, say"
                            value={formik.values.out_of_stock_status}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.out_of_stock_status &&
                                formik.errors.out_of_stock_status
                            }
                            placeholder="2-3 Days"
                            helperText="Blank reads 'Out of Stock'."
                        />
                    </div>

                    <Checkbox
                        id="emi_available"
                        name="emi_available"
                        label="Offer EMI on this product"
                        checked={formik.values.emi_available}
                        onChange={formik.handleChange}
                    />

                    <SpecificationEditor formik={formik} />

                    {/* Written for a search result, not for the page. The shop
                        this follows keeps the two apart on purpose: the title
                        reads "… Laptop Price in Bangladesh", the product name
                        reads "… Core i5 13th Gen RTX 3050 15.6-inch FHD". Blank
                        falls back to the name, exactly as before. */}
                    <details className="admin-seo-block">
                        <summary>Search engine listing (optional)</summary>

                        <FormInput
                            id="meta_title"
                            name="meta_title"
                            label="Meta Title"
                            value={formik.values.meta_title}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.meta_title &&
                                formik.errors.meta_title
                            }
                            placeholder="MSI Cyborg 15 A13UC Laptop Price in Bangladesh"
                            helperText="Blank uses the product name."
                        />

                        <FormInput
                            id="meta_description"
                            name="meta_description"
                            type="textarea"
                            rows={3}
                            label="Meta Description"
                            value={formik.values.meta_description}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.meta_description &&
                                formik.errors.meta_description
                            }
                            placeholder="Buy … at best price in Bangladesh. Order online for delivery in BD."
                            helperText="Around 155 characters is what Google shows."
                        />

                        <FormInput
                            id="meta_keyword"
                            name="meta_keyword"
                            label="Meta Keywords"
                            value={formik.values.meta_keyword}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            error={
                                formik.touched.meta_keyword &&
                                formik.errors.meta_keyword
                            }
                            placeholder='Core i5 13th Gen RTX 3050 15.6" FHD Gaming Laptop'
                        />
                    </details>

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

            <ProductDetailsModal
                productId={detailsId}
                isOpen={detailsId !== null}
                onClose={() => setDetailsId(null)}
                onEdit={handleOpenEdit}
            />
        </AdminLayout>
    );
}
