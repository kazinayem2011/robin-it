import React, { useState, useRef, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Tabs from '@/Components/Tabs';
import ImageLightbox from '@/Components/ImageLightbox';
import { photosOf } from '@/utils/productPhotos';
import { applyServerErrors } from '@/utils/serverErrors';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Package,
    Plus,
    Edit2,
    Eye,
    AlertTriangle,
    Tag,
    FileText,
    SlidersHorizontal,
    Image as ImageIcon,
    Globe,
    ChevronLeft,
    ChevronRight,
    PackagePlus,
    Copy,
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
import AttributeEditor from './Components/AttributeEditor';
import ProductDetailsModal from './Components/ProductDetailsModal';
import ImageGalleryEditor from '@/Components/ImageGalleryEditor';
import CategoryPicker from '@/Components/CategoryPicker';
import RichTextEditor from '@/Components/RichTextEditor';
import { bulletsToLines, linesToBullets } from '@/utils/bulletHtml';
import { ROUTES } from '@/constants/endpoints';
import { categoryPath } from '@/utils/categoryPath';
import { withoutIdentity, CLEARED_BY_COPY } from '@/utils/productCopy';

/**
 * Shape the form values for the API.
 *
 * Blank numeric strings are dropped rather than sent as '', and `opening_stock`
 * is sent only while splitting an existing product's shelf across new options,
 * where it allocates stock that is already there. No quantity is ever sent for
 * a new product or an existing option: stock enters under Purchasing.
 */
export const buildProductPayload = (values, editingProduct) => {
    const { variants, has_variants: hasVariants, ...rest } = values;

    // A quantity never leaves this form, creating or editing. Stock enters the
    // shop under Purchasing and nowhere else.
    delete rest.stock_quantity;

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

    rest.images = (rest.images || [])
        .filter((img) => img.image_path)
        .map((img) => ({
            id: img.id || undefined,
            image_path: img.image_path,
            alt_text: img.alt_text || null,
            is_primary: Boolean(img.is_primary),
        }));

    // Named explicitly, like every other field: this builder is an allowlist,
    // so anything not mentioned here never reaches the server.
    rest.attribute_value_ids = (rest.attribute_value_ids || []).map(Number);

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
                /*
                 * The option's own photos. This builder names every field it
                 * sends, so a new one is invisible until it is added here —
                 * the gallery reordered on screen, image_url followed it, and
                 * the photos themselves never moved.
                 */
                images: (variant.images || []).map((img) => ({
                    id: img.id || undefined,
                    image_path: img.image_path,
                    alt_text: img.alt_text || null,
                    is_primary: Boolean(img.is_primary),
                })),
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

            /*
             * Only when splitting an existing product's shelf across new
             * options. It is an allocation of stock that is already there —
             * the numbers must sum to what is on hand — never a way to add
             * any, which is what Purchasing is for.
             */
            if (isConverting) {
                line.opening_stock = Number(variant.opening_stock) || 0;
            }

            return line;
        }),
    };
};

/**
 * The product form, in the order somebody fills one in.
 *
 * Twenty-seven fields and five editors used to sit in one scroll, so entering
 * a laptop meant passing the SEO boxes to reach the warranty. They are grouped
 * now, and the grouping carries a cost worth naming: a field on a panel you
 * cannot see can be the one holding the save up. Every tab therefore counts
 * its own problems and says so, and a refused save opens the first tab that
 * has one.
 */
const PRODUCT_TABS = [
    { key: 'basics', label: 'Basics', icon: Package },
    { key: 'pricing', label: 'Price & stock', icon: Tag },
    { key: 'description', label: 'Description', icon: FileText },
    { key: 'specs', label: 'Specs & filters', icon: SlidersHorizontal },
    { key: 'photos', label: 'Photos', icon: ImageIcon },
    { key: 'publishing', label: 'Publishing', icon: Globe },
];

/** Which panel each field is on, for the counts and the jump. */
const TAB_FIELDS = {
    basics: ['name', 'category_id', 'category_ids', 'brand_id', 'model', 'mpn'],
    pricing: [
        'price',
        'discount_price',
        'reorder_level',
        'barcode',
        'variants',
        'allow_preorder',
        'preorder_limit',
        'preorder_release_at',
        'discount_starts_at',
        'discount_ends_at',
        'min_order_quantity',
        'checkout_discount',
        'emi_available',
        'emi_max_months',
        'out_of_stock_status',
    ],
    description: [
        'short_description',
        'description',
        'key_features',
        'warranty_months',
        'warranty_text',
    ],
    specs: ['attribute_value_ids', 'specifications'],
    photos: ['images', 'image_path'],
    publishing: [
        'meta_title',
        'meta_description',
        'meta_keyword',
        'is_active',
        'is_featured',
    ],
};

/**
 * How many of a tab's fields the form is currently unhappy about.
 *
 * Matched on the root of the key, because the two sources spell a nested field
 * differently: formik leaves `variants` holding an array of row errors, while
 * the server names `variants.1.sku` outright. Comparing whole keys finds the
 * first and misses the second — which is the repeated stock code, the one
 * complaint that most needs the tab opened for it.
 */
export const problemsOn = (tabKey, errors = {}) => {
    const roots = new Set(
        Object.keys(errors ?? {})
            .filter((key) => Boolean(errors[key]))
            .map((key) => String(key).split(/[.[]/)[0]),
    );

    return (TAB_FIELDS[tabKey] ?? []).filter((field) => roots.has(field))
        .length;
};

/** The first tab holding a problem, so a refused save can open it. */
export const firstTabWithProblem = (errors = {}) =>
    PRODUCT_TABS.find((t) => problemsOn(t.key, errors) > 0)?.key ?? null;

export default function Products({
    products = { data: [] },
    brands = [],
    selectedCategory: initialCategory = '',
    search = '',
    needsSpecs = false,
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
            brand_id: '',
            price: '',
            discount_price: '',
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
            attribute_value_ids: [],
            image_path: '',
            images: [],
            is_featured: false,
            /*
             * A new product starts as a draft.
             *
             * This used to be true, so "Create Product" meant "publish", and
             * the form actively encourages saving early — stock cannot be
             * received against a product that does not exist yet. The result
             * was live pages with no photograph, no spec sheet and no filter
             * answers, which is worse than not being listed: unfindable in the
             * sidebar, and the shop looks broken to anyone who does reach it.
             */
            is_active: false,
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
        onSubmit: async (
            values,
            { setSubmitting, resetForm, setFieldError, setFieldTouched },
        ) => {
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

                /*
                 * Both halves of what the server said: the fields it named get
                 * marked, and the sentence goes to a toast. Only the sentence
                 * used to arrive, so "Stock code X is on more than one option
                 * here" reached the reader with nothing on the form pointing
                 * at which option it meant.
                 */
                const marked = applyServerErrors(
                    { setFieldError, setFieldTouched },
                    error,
                );

                /*
                 * Open the panel holding the first complaint. Grouping the
                 * form put fields out of sight, so a refused save that marked
                 * something on a closed tab would be harder to act on than the
                 * single scroll this replaced.
                 */
                const landing = firstTabWithProblem(error?.errors ?? {});

                if (landing) setTab(landing);

                toast.error(
                    error?.message ||
                        'Failed to save product. Please check values.',
                    marked > 0 ? 'Check the marked fields' : 'Save Error',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    /*
     * Closing a form with work in it asks first.
     *
     * The modal closed on a click outside, on Escape and on the cross, and
     * threw everything away without a word — which on a product form is a
     * description somebody has just spent ten minutes writing, and there is
     * nothing to recover it from. Only when there is something to lose:
     * opening the form and closing it again should not be an interrogation.
     */
    /*
     * Which product's photos are being looked at, if any. The list draws a
     * 40px thumbnail per row and that was the whole of what the admin could
     * see of a product's photography without opening the edit form.
     */
    const [viewingPhotos, setViewingPhotos] = useState(null);
    const [photoIndex, setPhotoIndex] = useState(0);

    const openPhotos = (product) => {
        setPhotoIndex(0);
        setViewingPhotos(product);
    };

    const [tab, setTab] = useState('basics');

    /*
     * A count beside each tab's name, so a problem on a panel that is not open
     * still announces itself. Without it, grouping the form would have made a
     * refused save harder to act on than the single scroll it replaced.
     */
    /*
     * Pressing Save on a form whose problems are all on another panel would
     * otherwise do nothing visible at all.
     */
    /*
     * How many questions this product's shelf asks, reported by AttributeEditor.
     *
     * Needed to tell "this shelf asks nothing" apart from "this shelf asks and
     * nothing was answered". Only the second is a reason to stop a publish —
     * most shelves declare no filters at all yet.
     */
    const [filtersOffered, setFiltersOffered] = useState(0);

    /*
     * What is missing that a shopper would notice, on a product about to go
     * live. Not validation: every one of these is a legitimate thing to
     * publish deliberately, so it asks rather than refuses.
     */
    const thinPublishReasons = () => {
        const v = formik.values;
        const reasons = [];

        if ((v.images || []).length === 0 && !v.image_path) {
            reasons.push('no photograph — the page will show a placeholder');
        }

        if (filtersOffered > 0 && (v.attribute_value_ids || []).length === 0) {
            reasons.push(
                'no filter answers — it will not appear when shoppers narrow this category down',
            );
        }

        return reasons;
    };

    const [publishWarning, setPublishWarning] = useState(null);

    /*
     * Where the walk has got to. Derived from the open tab rather than held
     * separately, so clicking a tab header and pressing Next stay in step —
     * two sources for one position is how a wizard starts disagreeing with
     * itself.
     */
    const stepIndex = Math.max(
        0,
        PRODUCT_TABS.findIndex((entry) => entry.key === tab),
    );
    const onLastStep = stepIndex === PRODUCT_TABS.length - 1;

    /*
     * Put it down and come back to it.
     *
     * Draft is what is_active already holds on a new product, so this is an
     * ordinary submit — named for what it does, because "Save" beside a
     * "Publish Now" is ambiguous about which one goes live.
     */
    const saveDraft = async () => {
        const problems = await formik.validateForm();
        const landing = firstTabWithProblem(problems);

        if (landing) setTab(landing);

        formik.setFieldValue('is_active', false);
        formik.submitForm();
    };

    /*
     * Pressing Save on a form whose problems are all on another panel would
     * otherwise do nothing visible at all.
     */
    const handleSubmit = async (event) => {
        event.preventDefault();

        const problems = await formik.validateForm();
        const landing = firstTabWithProblem(problems);

        if (landing) {
            setTab(landing);
            formik.handleSubmit(event);

            return;
        }

        /*
         * Asked on the way into publication, not on every save of something
         * already live. A shop that knowingly keeps thin pages up should not
         * be nagged about them each time it corrects a price.
         */
        /*
         * On a new product the only submit that reaches here is Publish Now,
         * so it publishes — the Publishing tab's checkbox stays the record of
         * what was chosen, and Save as Draft sets it the other way before
         * submitting.
         */
        if (!editingProduct) {
            formik.setFieldValue('is_active', true);
        }

        const publishing = editingProduct
            ? formik.values.is_active && !editingProduct.is_active
            : true;
        const reasons = publishing ? thinPublishReasons() : [];

        if (reasons.length > 0) {
            setPublishWarning(reasons);

            return;
        }

        formik.handleSubmit(event);
    };

    const tabsWithProblems = PRODUCT_TABS.map((entry) => {
        const problems = problemsOn(entry.key, formik.errors);

        return problems > 0 ? { ...entry, badge: problems } : entry;
    });

    /*
     * Taking a product down from the list.
     *
     * Optimistic would be wrong here: this decides whether shoppers can see
     * it, and a row that flips back a second later because the save failed is
     * worse than a row that waits.
     */
    const [togglingId, setTogglingId] = useState(null);

    const toggleVisibility = async (product) => {
        setTogglingId(product.id);

        try {
            await adminService.updateProduct(product.id, {
                is_active: !product.is_active,
            });

            toast.success(
                product.is_active
                    ? `"${product.name}" is no longer shown to shoppers.`
                    : `"${product.name}" is live on the storefront.`,
            );

            router.reload({ only: ['products'], preserveScroll: true });
        } catch (error) {
            toast.error(error?.message || 'Could not change that.');
        } finally {
            setTogglingId(null);
        }
    };

    /*
     * Which product a filled-in form was copied from, so it can say so. Null
     * on an ordinary create, which is the difference the banner announces.
     */
    const [copiedFrom, setCopiedFrom] = useState(null);
    const [copyingId, setCopyingId] = useState(null);

    const [confirmingClose, setConfirmingClose] = useState(false);

    const closeModal = () => {
        setConfirmingClose(false);
        setTab('basics');
        setModalOpen(false);
        setEditingProduct(null);
        // Not part of the form values, so resetForm does not reach it — and
        // left behind it would follow into whatever is opened next.
        setExtraCategoryChips([]);
        setCopiedFrom(null);
        formik.resetForm();
    };

    const requestClose = () => {
        if (formik.dirty) {
            setConfirmingClose(true);

            return;
        }

        closeModal();
    };

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
        setExtraCategoryChips([]);
        setCopiedFrom(null);
        formik.resetForm({
            values: {
                name: '',
                category_id: '',
                brand_id: '',
                price: '',
                discount_price: '',
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
                attribute_value_ids: [],
                image_path: '',
                images: [],
                is_featured: false,
                // Draft, as above.
                is_active: false,
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

    /*
     * Put a product's values into the form.
     *
     * Shared by editing and copying: a copy is the same mapping over a product
     * with the fields that identify a particular item stripped off, so the two
     * cannot drift into disagreeing about what a field is called.
     */
    const loadIntoForm = (p) => {
        /*
         * The chips for "Also list under".
         *
         * These were never populated on edit — the state started empty and
         * only ever grew as somebody picked — so a product already listed
         * under three shelves opened showing none of them. The ids were
         * loaded and saved correctly underneath, so nothing was lost; it just
         * could not be seen or removed.
         *
         * The primary is excluded: it has its own field above, and the server
         * adds it back regardless.
         */
        setExtraCategoryChips(
            (p.categories || [])
                .filter((c) => Number(c.id) !== Number(p.category_id))
                .map((c) => ({
                    id: c.id,
                    name: c.name,
                    path: categoryPath(c),
                })),
        );

        formik.resetForm({
            values: {
                name: p.name || '',
                category_id: p.category_id || '',
                /*
                 * Not brands[0]. Falling back to the first row in the table
                 * meant every one of the 1,267 brandless products opened with
                 * "Intel" already chosen — and saving anything at all, a price
                 * or a typo in the name, filed it under Intel for good. The
                 * empty value is a real option here ("No Brand / Generic"), so
                 * an absent brand can stay absent.
                 */
                brand_id: p.brand_id || '',
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
                attribute_value_ids: (p.attribute_values || []).map(
                    (v) => v.id,
                ),
                specifications: (p.specifications || []).map((spec, i) => ({
                    key: `spec-${spec.id ?? i}`,
                    group: spec.group || '',
                    name: spec.name || '',
                    value: spec.value || '',
                })),
                image_path: p.images?.[0]?.image_path || '',
                images: (p.images || []).map((img) => ({
                    id: img.id,
                    image_path: img.image_path,
                    alt_text: img.alt_text || '',
                    is_primary: Boolean(img.is_primary),
                })),
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
                        images: (v.images || []).map((img) => ({
                            id: img.id,
                            image_path: img.image_path,
                            alt_text: img.alt_text || '',
                            is_primary: Boolean(img.is_primary),
                        })),
                    })),
            },
        });
    };

    const handleOpenEdit = (p) => {
        setEditingProduct(p);
        /*
         * Belt and braces, and deliberately so: closing already clears this,
         * and no path opens an edit without closing first, so removing the
         * line breaks no test today. It is here because every other entry
         * point states what the form is rather than inheriting it, and an
         * edit claiming to be a copy would be describing fields it never
         * touched — on the one screen somebody checks to find out what
         * happened to a value.
         */
        setCopiedFrom(null);
        loadIntoForm(p);
        setModalOpen(true);
    };

    /*
     * Start a new product from an existing one.
     *
     * A create, not a write: the form opens filled in and nothing exists until
     * it is saved, so thinking better of it costs nothing and leaves no half-
     * finished row behind. It also means every field is reviewed before it
     * reaches the catalogue, which matters most for the ones a copy is likely
     * to get wrong.
     *
     * The thin row in the table is not enough to copy from — it carries no
     * spec sheet, no filter answers, no options — so the full product is
     * fetched first. That request is the same one the details panel makes.
     */
    const copyFrom = async (product) => {
        setCopyingId(product.id);

        try {
            const response = await adminService.getProduct(product.id);
            const source = response?.data;

            if (!source) throw new Error('Could not read that product.');

            setEditingProduct(null);
            setTab('basics');
            loadIntoForm(withoutIdentity(source));
            setCopiedFrom(source.name);
            setModalOpen(true);
        } catch (error) {
            toast.error(error?.message || 'Could not copy that product.');
        } finally {
            setCopyingId(null);
        }
    };

    const columns = [
        {
            key: 'details',
            header: 'Product Details',
            render: (p) => (
                <div className="admin-product-item-flex">
                    <button
                        type="button"
                        className="admin-product-item-thumb-open"
                        aria-label={`View photos of ${p.name}`}
                        onClick={() => openPhotos(p)}
                    >
                        <ProductImage
                            product={p}
                            alt={p.name}
                            className="admin-product-item-thumb"
                        />
                    </button>
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
            /*
             * With its ancestry above it. Four shelves are called Asus and
             * several Accessories, so the leaf alone told you almost nothing
             * about where a product had actually been filed.
             */
            render: (p) =>
                p.category?.name ? (
                    <span className="admin-table-category">
                        {categoryPath(p.category) && (
                            <small>{categoryPath(p.category)} ›</small>
                        )}
                        <span className="admin-table-item-title">
                            {p.category.name}
                        </span>
                    </span>
                ) : (
                    <span className="admin-field-hint">Unfiled</span>
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
            /*
             * The figure only. Receiving is an action and now sits with the
             * other actions — a button in a column of numbers made the column
             * hard to read down, which is the one thing a stock column is for.
             *
             * There used to be a "+5 Stock" button here as well, which added
             * five units with no supplier, no cost and no record of who did
             * it. Restocking goes through a delivery.
             */
            render: (p) => (
                <span
                    className={`${
                        p.stock_quantity <= 5
                            ? 'admin-badge-stock-danger'
                            : 'admin-badge-stock-ok'
                    }`}
                >
                    {p.stock_quantity <= 5 && '⚠️ '}
                    {p.stock_quantity} in Stock
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Visibility',
            /*
             * A button, not a label. Taking a product down was six clicks —
             * open it, find the Publishing tab, untick, save — for the one
             * action most likely to be wanted in a hurry, when something is
             * listed wrong and a shopper can see it.
             */
            render: (p) => (
                /*
                    A switch, drawn as one.
                    
                    It was a coloured word that happened to be clickable, which
                    nobody would ever try: the colour said "state", and nothing
                    said "control". A track with a knob in it is the one shape
                    people already know means this can be flipped, and the word
                    beside it still says which way it is.
                */
                <button
                    type="button"
                    role="switch"
                    aria-checked={Boolean(p.is_active)}
                    className={`admin-visibility-toggle${
                        p.is_active ? ' is-on' : ''
                    }`}
                    disabled={togglingId === p.id}
                    onClick={() => toggleVisibility(p)}
                    title={
                        p.is_active
                            ? 'Shown to shoppers. Click to take it down.'
                            : 'Hidden from shoppers. Click to publish it.'
                    }
                >
                    <span className="admin-visibility-track" aria-hidden="true">
                        <span className="admin-visibility-knob" />
                    </span>
                    <span className="admin-visibility-label">
                        {p.is_active ? 'Active' : 'Inactive'}
                    </span>
                </button>
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
                    {/*
                        Carries the product across, so the stock screen opens
                        filtered to it rather than to thirteen hundred rows.
                    */}
                    <Link
                        href={`${ROUTES.ADMIN_STOCK}?search=${encodeURIComponent(p.name || '')}`}
                        className="admin-table-icon-btn"
                        title="Record a delivery for this product"
                        aria-label={`Receive stock for ${p.name}`}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <PackagePlus size={14} />
                    </Link>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        disabled={copyingId === p.id}
                        onClick={() => copyFrom(p)}
                        title="Start a new product from this one"
                        aria-label={`Copy ${p.name}`}
                    >
                        <Copy size={14} />
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

    /*
     * A photo is appended to a gallery now, not written over the one slot
     * there used to be. `cropTarget` says which gallery: the product's, or
     * one option's, identified by its row key.
     *
     * The first photo added leads; the editor marks it and lets it be
     * changed. is_primary is not set here, because ImageGalleryEditor keeps
     * it in step with position on every change — two places deciding which
     * photo is first is how they end up disagreeing.
     */
    const handleCropComplete = async ({ file }) => {
        /*
         * The modal is not closed here any more — it closes itself once the
         * photos it was given are dealt with. Closing on the first completion
         * ended the errand after one of six.
         */
        setUploadingImage(true);
        try {
            const { path } = await uploadService.uploadImage(file, 'products');
            const photo = { image_path: path, alt_text: '', is_primary: false };

            if (cropTarget === 'product') {
                const next = [...(formik.values.images || []), photo].map(
                    (img, i) => ({ ...img, is_primary: i === 0 }),
                );
                formik.setFieldValue('images', next);
                // Kept in step for anything still posting the single field.
                formik.setFieldValue('image_path', next[0]?.image_path || '');
            } else {
                formik.setFieldValue(
                    'variants',
                    (formik.values.variants || []).map((v) => {
                        if (v.key !== cropTarget) return v;

                        const images = [...(v.images || []), photo].map(
                            (img, i) => ({ ...img, is_primary: i === 0 }),
                        );

                        return {
                            ...v,
                            images,
                            image_url: images[0]?.image_path || '',
                        };
                    }),
                );
            }
        } catch (err) {
            toast.error(
                err?.message || 'Could not upload that image.',
                'Upload Failed',
            );
        } finally {
            setUploadingImage(false);
            /*
             * `cropTarget` is deliberately left alone. It says which gallery
             * these photos belong to, and the modal may still have more of
             * them queued — resetting it here sent the second photo of an
             * option's gallery to the product's. It is cleared when the modal
             * closes, which is when the errand is actually over.
             */
        }
    };

    /** One option's gallery changed: keep its lead shot on image_url too. */
    const setVariantImages = (variantKey, images) =>
        formik.setFieldValue(
            'variants',
            (formik.values.variants || []).map((v) =>
                v.key === variantKey
                    ? { ...v, images, image_url: images[0]?.image_path || '' }
                    : v,
            ),
        );

    return (
        <AdminLayout
            title="Products & Inventory"
            subtitle={`Manage ${siteConfig.name} Hardware Catalog, Live Stock Levels & Pricing`}
        >
            <Head title={`Admin Products & Inventory — ${siteConfig.name}`} />

            {/*
             * Arriving from the PC Builder's "Fix", which sends only the
             * products it cannot check. Without saying so the list looks like
             * the catalogue has lost eleven hundred rows.
             */}
            {needsSpecs && (
                <div className="admin-spec-filter-note">
                    <AlertTriangle size={16} />
                    <div>
                        <strong>
                            Showing only the products the PC Builder cannot
                            check
                        </strong>
                        <p>
                            Each one is missing a specification the builder
                            reads — the red note under its name says which. Open
                            it, add them under Specifications, and save.
                        </p>
                    </div>
                    <Link href={ROUTES.ADMIN_PRODUCTS}>Show all products</Link>
                </div>
            )}

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

            {viewingPhotos && (
                <ImageLightbox
                    images={photosOf(viewingPhotos)}
                    index={photoIndex}
                    alt={viewingPhotos.name}
                    product={viewingPhotos}
                    onIndexChange={setPhotoIndex}
                    onClose={() => setViewingPhotos(null)}
                />
            )}

            <Modal
                isOpen={modalOpen}
                onClose={requestClose}
                title={
                    editingProduct
                        ? `Edit Product: ${editingProduct.name}`
                        : 'Add New Technology Product'
                }
                maxWidth="860px"
            >
                <form onSubmit={handleSubmit} noValidate>
                    {/*
                        Says where the values came from and what was left out.
                        A form that fills itself in is only safe if it is
                        honest about having done so — and the two empty fields
                        are the ones somebody would otherwise assume had simply
                        never been filled in on the original.
                    */}
                    {copiedFrom && (
                        <div className="admin-copy-banner">
                            <Copy size={15} />
                            <div>
                                <strong>Copied from {copiedFrom}.</strong>{' '}
                                Nothing is saved until you save it.
                                <ul>
                                    {CLEARED_BY_COPY.map(
                                        ({ field, label, why }) => (
                                            <li key={field}>
                                                <b>{label}</b> was left empty —{' '}
                                                {why}.
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        </div>
                    )}

                    <Tabs
                        tabs={tabsWithProblems}
                        activeTab={tab}
                        onChange={setTab}
                        variant="line"
                        className="admin-product-tabs"
                    />

                    {/*
                        role="tab" promises a panel that appears when its tab
                        is chosen — Tabs says as much in its own docblock. The
                        panel has to say which tab it belongs to for that
                        promise to hold.
                    */}
                    <div
                        className="admin-product-tabpanel"
                        role="tabpanel"
                        aria-label={
                            PRODUCT_TABS.find((t) => t.key === tab)?.label
                        }
                    >
                        {tab === 'basics' && (
                            <>
                                <FormInput
                                    id="name"
                                    name="name"
                                    required
                                    label="Product Title"
                                    value={formik.values.name}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.name &&
                                        formik.errors.name
                                    }
                                    placeholder="e.g. Intel Core i7-14700K 20-Core Processor"
                                />

                                <div className="admin-modal-form-grid">
                                    <CategoryPicker
                                        label="Category"
                                        required
                                        value={formik.values.category_id}
                                        initialLabel={
                                            editingProduct?.category?.name || ''
                                        }
                                        initialPath={categoryPath(
                                            editingProduct?.category,
                                        )}
                                        onChange={(id) =>
                                            formik.setFieldValue(
                                                'category_id',
                                                id,
                                            )
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
                                        error={
                                            formik.touched.model &&
                                            formik.errors.model
                                        }
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
                                        error={
                                            formik.touched.mpn &&
                                            formik.errors.mpn
                                        }
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
                                            (
                                                formik.values.category_ids || []
                                            ).filter((x) => x !== id),
                                        );
                                    }}
                                    onChange={(category) => {
                                        if (
                                            (
                                                formik.values.category_ids || []
                                            ).includes(category.id)
                                        ) {
                                            return;
                                        }
                                        setExtraCategoryChips((c) => [
                                            ...c,
                                            category,
                                        ]);
                                        formik.setFieldValue('category_ids', [
                                            ...(formik.values.category_ids ||
                                                []),
                                            category.id,
                                        ]);
                                    }}
                                />
                            </>
                        )}

                        {tab === 'pricing' && (
                            <>
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
                                        error={
                                            formik.touched.price &&
                                            formik.errors.price
                                        }
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
                                            formik.touched.barcode &&
                                            formik.errors.barcode
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
                                            checked={
                                                formik.values.allow_preorder
                                            }
                                            onChange={formik.handleChange}
                                        />
                                        <p className="admin-field-hint">
                                            Customers can buy this with an empty
                                            shelf. The balance goes negative by
                                            the number of units owed, and the
                                            next delivery clears it.
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
                                                value={
                                                    formik.values.preorder_limit
                                                }
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
                                                value={
                                                    formik.values
                                                        .preorder_release_at
                                                }
                                                onChange={formik.handleChange}
                                                onBlur={formik.handleBlur}
                                                helperText="Shown to the customer. A pre-order without a date is a delay they did not agree to."
                                            />
                                        </>
                                    )}

                                    {/*
                                     * Stock is shown, never typed — on a new product as
                                     * much as an existing one. What is on the shelf
                                     * arrives under Purchasing: from a supplier, or from
                                     * the "Opening balance" source for goods the shop
                                     * already held. One way in is the only way the ledger
                                     * can be trusted.
                                     */}
                                    <div className="auth-form-group">
                                        <label className="auth-label">
                                            Stock
                                        </label>
                                        <div className="admin-stock-readonly">
                                            <span className="admin-stock-readonly-qty">
                                                {editingProduct
                                                    ? editingProduct.has_variants
                                                        ? `${editingProduct.stock_quantity} across ${
                                                              (
                                                                  editingProduct.variants ||
                                                                  []
                                                              ).filter(
                                                                  (v) =>
                                                                      v.is_active,
                                                              ).length
                                                          } option(s)`
                                                        : `${editingProduct.stock_quantity} on hand`
                                                    : 'None yet'}
                                            </span>
                                            {/*
                                                A plain anchor opening a new
                                                tab, not an Inertia Link.
                                                Following it in this tab tears
                                                the modal down and takes every
                                                unsaved field with it — and it
                                                did so straight past the
                                                "Discard this product?" guard,
                                                because that only covers
                                                closing the modal, not
                                                navigating out from inside it.

                                                Offered only once the product
                                                exists. On a new one there is
                                                nothing to receive stock
                                                against, so the link could only
                                                ever lose work — while the hint
                                                beside it said to save first.
                                            */}
                                            {editingProduct && (
                                                <a
                                                    href={`${ROUTES.ADMIN_STOCK}?search=${encodeURIComponent(
                                                        editingProduct.name ||
                                                            '',
                                                    )}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="admin-stock-readonly-link"
                                                >
                                                    Receive or adjust
                                                </a>
                                            )}
                                        </div>
                                        <span className="admin-field-hint">
                                            {editingProduct
                                                ? 'Changed by deliveries, orders and recorded adjustments — never edited here. Opens in a new tab so this form is not lost.'
                                                : 'Save the product first. You can then receive what you hold against the "Opening balance" source.'}
                                        </span>
                                    </div>
                                </div>

                                <VariantEditor
                                    formik={formik}
                                    editingProduct={editingProduct}
                                    onImagesChange={setVariantImages}
                                    onPickImage={(variantKey) => {
                                        setCropTarget(variantKey);
                                        setCropperOpen(true);
                                    }}
                                    uploading={uploadingImage}
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
                                        value={
                                            formik.values.out_of_stock_status
                                        }
                                        onChange={formik.handleChange}
                                        onBlur={formik.handleBlur}
                                        error={
                                            formik.touched
                                                .out_of_stock_status &&
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
                            </>
                        )}

                        {tab === 'description' && (
                            <>
                                <FormInput
                                    id="short_description"
                                    name="short_description"
                                    label="Short Summary / Key Highlights"
                                    /*
                                     * 500 characters is three or four lines of
                                     * prose, and a single-line box showed one
                                     * of them — you wrote the summary through
                                     * a letterbox and could not read back what
                                     * you had written.
                                     */
                                    type="textarea"
                                    rows={3}
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
                                        formik.setFieldValue(
                                            'description',
                                            html,
                                        )
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

                                {/* A warranty is a list of clauses, not a sentence: what
                            is covered, what is not, what the customer has to keep.
                            One line could not hold them, and the column could not
                            either until it became `text`. */}
                                <FormInput
                                    id="warranty_text"
                                    name="warranty_text"
                                    type="textarea"
                                    rows={5}
                                    label="Warranty Terms"
                                    value={formik.values.warranty_text}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.warranty_text &&
                                        formik.errors.warranty_text
                                    }
                                    placeholder={
                                        '2 Years warranty on the unit\n' +
                                        'Battery and adapter: 1 year\n' +
                                        'Physical damage and burn marks are not covered\n' +
                                        'Keep the box and the invoice for any claim'
                                    }
                                    helperText="One clause per line. What the customer is told; the months above are what the claims system counts."
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
                            </>
                        )}

                        {tab === 'specs' && (
                            <>
                                <AttributeEditor
                                    formik={formik}
                                    onCount={setFiltersOffered}
                                />

                                <SpecificationEditor formik={formik} />
                            </>
                        )}

                        {tab === 'photos' && (
                            <>
                                {/* A product's photos. This was one path field and one
                            photo, so nothing could show the back of a box or what
                            is in the carton — the table always could hold more. */}
                                <ImageGalleryEditor
                                    label="Product Photos"
                                    images={formik.values.images || []}
                                    busy={uploadingImage}
                                    onPick={() => {
                                        setCropTarget('product');
                                        setCropperOpen(true);
                                    }}
                                    onChange={(images) => {
                                        formik.setFieldValue('images', images);
                                        formik.setFieldValue(
                                            'image_path',
                                            images[0]?.image_path || '',
                                        );
                                    }}
                                    helperText="The first photo is the one shown on the catalogue card, in the cart and in search results. Reorder with the arrows."
                                    emptyHint="No photos yet — this product will show the placeholder."
                                />
                            </>
                        )}

                        {tab === 'publishing' && (
                            <>
                                {/* Written for a search result, not for the page. The shop
                            this follows keeps the two apart on purpose: the title
                            reads "… Laptop Price in Bangladesh", the product name
                            reads "… Core i5 13th Gen RTX 3050 15.6-inch FHD". Blank
                            falls back to the name, exactly as before. */}
                                <details className="admin-seo-block">
                                    <summary>
                                        Search engine listing (optional)
                                    </summary>

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

                                {/*
                                    Said out loud, because the default changed:
                                    a new product used to go live the moment it
                                    was created, and somebody who knows that is
                                    otherwise left wondering where it went.
                                */}
                                {!editingProduct &&
                                    !formik.values.is_active && (
                                        <span className="admin-field-hint">
                                            Leave this unticked to save a draft.
                                            Nothing is shown to shoppers until
                                            it is on, so you can come back and
                                            finish the photos and filters first.
                                        </span>
                                    )}
                            </>
                        )}
                    </div>

                    {/*
                        Two footers, because the two jobs are not the same one.
                        Entering a product is a walk through six panels in
                        order; correcting one is opening the panel that is
                        wrong and saving. A wizard over an edit would make a
                        price change a six-step errand.
                    */}
                    {editingProduct ? (
                        <div className="admin-modal-footer-btns">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={requestClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="primary"
                                loading={formik.isSubmitting}
                            >
                                Update Product
                            </Button>
                        </div>
                    ) : (
                        <div className="admin-modal-footer-btns admin-wizard-footer">
                            {/*
                                Saving is always available, on every step. The
                                walk is a suggestion about order, not a gate —
                                somebody who only wants a name and a price
                                should not have to click through four panels to
                                put it down.
                            */}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={saveDraft}
                                loading={formik.isSubmitting}
                            >
                                Save as Draft
                            </Button>

                            <div className="admin-wizard-steps">
                                <span className="admin-wizard-count">
                                    Step {stepIndex + 1} of{' '}
                                    {PRODUCT_TABS.length}
                                </span>

                                <Button
                                    type="button"
                                    variant="secondary"
                                    icon={ChevronLeft}
                                    disabled={stepIndex === 0}
                                    onClick={() =>
                                        setTab(PRODUCT_TABS[stepIndex - 1].key)
                                    }
                                >
                                    Previous
                                </Button>

                                {onLastStep ? (
                                    /*
                                        The one button that publishes. It runs
                                        the same guard as any other publish, so
                                        a product with no photograph or no
                                        filter answers is still asked about.
                                    */
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        loading={formik.isSubmitting}
                                    >
                                        Publish Now
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="primary"
                                        iconPosition="right"
                                        icon={ChevronRight}
                                        onClick={() =>
                                            setTab(
                                                PRODUCT_TABS[stepIndex + 1].key,
                                            )
                                        }
                                    >
                                        Next
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </form>
            </Modal>

            {/*
                After the modal they interrupt, not before it.

                Every backdrop in the shop is z-index 9999, so with equal
                stacking the later element in the DOM paints on top. These
                sat above the product modal and were drawn underneath it:
                pressing Cancel opened the question and hid it, so the
                modal appeared to ignore the click entirely.

                jsdom has neither painting nor stacking, so a test can find
                the dialog either way — the order is asserted instead.
            */}
            {/*
                Asks rather than refuses. Publishing a thin page is a real
                choice a shop sometimes makes — a placeholder while the
                photographs are being taken — so this names what a shopper
                would notice and lets it through.
            */}
            <ConfirmDialog
                isOpen={Boolean(publishWarning)}
                title="Publish it like this?"
                message={
                    <>
                        This will go live on the storefront with:
                        <ul className="admin-thin-publish-list">
                            {(publishWarning || []).map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                        You can save it as a draft instead and finish it first.
                    </>
                }
                confirmLabel="Publish anyway"
                cancelLabel="Go back"
                variant="primary"
                onConfirm={() => {
                    setPublishWarning(null);
                    formik.submitForm();
                }}
                onCancel={() => setPublishWarning(null)}
            />

            <ConfirmDialog
                isOpen={confirmingClose}
                title="Discard this product?"
                message="What you have typed here has not been saved. Closing now loses it."
                confirmLabel="Discard"
                cancelLabel="Keep editing"
                onConfirm={closeModal}
                onCancel={() => setConfirmingClose(false)}
            />

            {/* Single Unified Product Modal (Create & Edit SSOT) */}

            {cropperOpen && (
                /*
                 * 4:3, because that is the shape of the frame a product card
                 * draws it in.
                 *
                 * This cropped to a square, and every card then letterboxed it:
                 * measured on the listing, an 800x800 upload painted 189x189
                 * inside a 253x189 box — three quarters of the width, with 32px
                 * of empty bar down each side. The photo was not wrong, it was
                 * cut to a shape nothing displays.
                 */
                <ImageCropperModal
                    isOpen={cropperOpen}
                    onClose={() => {
                        setCropperOpen(false);
                        setCropTarget('product');
                    }}
                    onCropComplete={handleCropComplete}
                    aspectRatio={4 / 3}
                    lockAspect
                    multiple
                    targetWidth={1200}
                    title="Crop Product Image (4:3)"
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
