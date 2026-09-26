import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import Checkbox from '../../Components/Checkbox';
import FormInput from '../../Components/FormInput';
import Select from '../../Components/Select';
import ImageCropperModal from '../../Components/ImageCropperModal';
import Modal from '../../Components/Modal';
import Tabs from '../../Components/Tabs';
import { toast } from '../../Components/Toast';
import { adminService, uploadService } from '../../services';
import { adminBannerSchema } from '../../validations';

import { Plus, Trash2, Edit3, Crop } from 'lucide-react';

/*
 * The two places a banner can go on the homepage, each its own list here.
 *
 * They were one grid told apart by a small "HERO" / "PROMO_SIDE" tag, with two
 * more placements on offer — a top bar and a popup — that nothing on the site
 * ever showed. The shapes are the ones the homepage draws them at: the slider
 * about 2.4 wide to 1 high, a promo card 16:10.
 */
export const BANNER_TYPES = {
    hero: {
        key: 'hero',
        tab: 'Hero slider',
        one: 'hero slide',
        numbered: 'Slide',
        where: 'The large rotating banner at the top of the homepage. Slides show in the order below.',
        size: '1920 × 800 px',
        aspect: 12 / 5,
        cropTitle: 'Crop hero slide (12:5)',
    },
    promo: {
        key: 'promo',
        tab: 'Promo cards',
        one: 'promo card',
        numbered: 'Card',
        where: 'The row of offer cards below the hero slider, three to a row. Cards show in the order below.',
        size: '800 × 500 px',
        aspect: 16 / 10,
        cropTitle: 'Crop promo card (16:10)',
    },
};

/** Which list a saved banner belongs in; promo_top was shown as a card. */
export const bannerType = (position) =>
    position === 'hero' ? 'hero' : 'promo';

/** What is stored for a list. */
const positionFor = (type) => (type === 'hero' ? 'hero' : 'promo_side');

const readTab = () => {
    try {
        return new URLSearchParams(window.location.search).get('type') ===
            'promo'
            ? 'promo'
            : 'hero';
    } catch {
        return 'hero';
    }
};

export default function AdminBanners({ banners = [] }) {
    const [activeType, setActiveType] = useState(readTab);

    const groups = useMemo(() => {
        const byType = { hero: [], promo: [] };
        banners.forEach((b) => byType[bannerType(b.position)].push(b));
        Object.values(byType).forEach((list) =>
            list.sort(
                (a, b) =>
                    (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.id - b.id,
            ),
        );
        return byType;
    }, [banners]);

    const type = BANNER_TYPES[activeType];
    const list = groups[activeType];

    const switchType = (key) => {
        setActiveType(key);
        // Kept in the address, so a reload or a shared link opens the same list.
        try {
            const url = new URL(window.location.href);
            if (key === 'promo') url.searchParams.set('type', 'promo');
            else url.searchParams.delete('type');
            window.history.replaceState(window.history.state, '', url);
        } catch {
            // Only a convenience.
        }
    };
    const [modalOpen, setModalOpen] = useState(false);
    const [cropperOpen, setCropperOpen] = useState(false);
    const [uploadingImage, setUploadingImage] = useState(false);
    const [editingBanner, setEditingBanner] = useState(null);

    const formik = useFormik({
        initialValues: {
            title: '',
            subtitle: '',
            badge: '',
            image_path: '',
            link_url: '/shop',
            button_text: 'Shop Now',
            position: 'hero',
            sort_order: 1,
            is_active: true,
        },
        validationSchema: adminBannerSchema,
        // No enableReinitialize here: `initialValues` is a blank literal that is
        // rebuilt on every render, so Formik would keep resetting the form back
        // to it and wipe the values handleOpenEdit had just loaded. Editing a
        // record opened a completely empty form because of that.
        onSubmit: async (values, { setSubmitting }) => {
            try {
                if (editingBanner) {
                    await adminService.updateBanner(editingBanner.id, values);
                    toast.success(`Saved the ${formType.one}.`);
                } else {
                    await adminService.createBanner(values);
                    toast.success(`Added the ${formType.one}.`);
                }
                setModalOpen(false);
                // Moved to the other list: follow it there.
                switchType(bannerType(values.position));
                router.reload({ only: ['banners'] });
            } catch (err) {
                toast.error(err?.message || 'Failed to save banner.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    // The form's own kind, from its "Shows as" choice: the crop shape, image
    // size and wording follow it, not the tab it was opened from.
    const formType = BANNER_TYPES[bannerType(formik.values.position)];

    const handleOpenCreate = () => {
        setEditingBanner(null);
        formik.resetForm({
            values: {
                title: '',
                subtitle: '',
                badge: '',
                image_path: '',
                link_url: '/shop',
                button_text: 'Shop Now',
                position: positionFor(activeType),
                // After the last one in this list, not after every banner.
                sort_order:
                    list.reduce(
                        (max, b) => Math.max(max, b.sort_order ?? 0),
                        0,
                    ) + 1,
                is_active: true,
            },
        });
        setModalOpen(true);
    };

    const handleOpenEdit = (banner) => {
        setEditingBanner(banner);
        formik.resetForm({
            values: {
                title: banner.title,
                subtitle: banner.subtitle || '',
                badge: banner.badge || '',
                image_path: banner.image_path,
                link_url: banner.link_url || '',
                button_text: banner.button_text || 'Shop Now',
                // A retired placement opens as the list it is shown in.
                position: positionFor(bannerType(banner.position)),
                sort_order: banner.sort_order || 1,
                is_active: !!banner.is_active,
            },
        });
        setModalOpen(true);
    };

    const handleDelete = async (id) => {
        if (!confirm(`Delete this ${type.one}? This cannot be undone.`)) {
            return;
        }
        try {
            await adminService.deleteBanner(id);
            toast.success(`Deleted the ${type.one}.`);
            router.reload({ only: ['banners'] });
        } catch (err) {
            toast.error(`Could not delete the ${type.one}.`);
        }
    };

    // The cropper hands back { dataUrl, blob, file, width, height }. This used to
    // treat that object as a URL string, so image_path became "[object Object]".
    // The cropped file is uploaded and the stored public path is kept instead.
    const handleCropComplete = async ({ file }) => {
        setCropperOpen(false);
        setUploadingImage(true);
        try {
            const { path } = await uploadService.uploadImage(file, 'banners');
            formik.setFieldValue('image_path', path);
            toast.success('Banner image uploaded.', 'Upload Complete');
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
            title="Banners &amp; Promo Cards"
            subtitle="The homepage's hero slider and the promo cards below it"
        >
            <Head title="Banners &amp; Promo Cards" />

            <div>
                <Tabs
                    variant="enclosed"
                    tabs={Object.values(BANNER_TYPES).map((t) => ({
                        key: t.key,
                        label: t.tab,
                        badge: groups[t.key].length,
                    }))}
                    activeTab={activeType}
                    onChange={switchType}
                />

                {/* The same bar as every table screen: heading left, action
                    right, one control height. */}
                <div className="admin-card-header">
                    <div className="admin-card-title-group">
                        <h3 className="admin-card-title">
                            {type.tab}: {list.filter((b) => b.is_active).length}{' '}
                            live
                            {list.some((b) => !b.is_active) &&
                                `, ${list.filter((b) => !b.is_active).length} hidden`}
                        </h3>
                        <span className="admin-table-item-sub">
                            {type.where} Images: {type.size}.
                        </span>
                    </div>

                    <div className="admin-header-actions">
                        <Button
                            variant="primary"
                            icon={Plus}
                            onClick={handleOpenCreate}
                        >
                            Add {type.one}
                        </Button>
                    </div>
                </div>

                {list.length === 0 && (
                    <p className="admin-banner-empty">
                        No {type.one}s yet. Nothing shows in this spot on the
                        homepage until you add one and switch it on.
                    </p>
                )}

                <div className={`admin-banners-grid is-${activeType}`}>
                    {list.map((b, idx) => (
                        <div
                            key={b.id}
                            className={`admin-banner-card${b.is_active ? '' : ' is-hidden'}`}
                        >
                            <div className="admin-banner-preview">
                                {b.image_path && (
                                    <img src={b.image_path} alt={b.title} />
                                )}
                                <span className="banner-pos-tag">
                                    {type.numbered} {idx + 1}
                                </span>
                            </div>
                            <div className="admin-banner-info">
                                {b.badge && (
                                    <span className="admin-banner-badge">
                                        {b.badge}
                                    </span>
                                )}
                                <h4>{b.title}</h4>
                                {b.subtitle && (
                                    <p className="banner-sub">{b.subtitle}</p>
                                )}
                                <div className="banner-meta-row">
                                    <span>
                                        Links to {b.link_url || '/shop'}
                                    </span>
                                    <span
                                        className={
                                            b.is_active
                                                ? 'banner-status-live'
                                                : 'banner-status-hidden'
                                        }
                                    >
                                        {b.is_active ? 'Live' : 'Hidden'}
                                    </span>
                                </div>
                            </div>
                            <div className="admin-banner-actions admin-table-icon-group">
                                <button
                                    type="button"
                                    className="admin-table-icon-btn"
                                    onClick={() => handleOpenEdit(b)}
                                    title={`Edit this ${type.one}`}
                                    aria-label={`Edit ${b.title || type.one}`}
                                >
                                    <Edit3 size={14} />
                                </button>
                                <button
                                    type="button"
                                    className="admin-table-icon-btn btn-danger"
                                    onClick={() => handleDelete(b.id)}
                                    title={`Delete this ${type.one}`}
                                    aria-label={`Delete ${b.title || type.one}`}
                                >
                                    <Trash2 size={14} />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Standard Reusable Modal Component */}
                <Modal
                    isOpen={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={
                        editingBanner
                            ? `Edit ${formType.one}`
                            : `Add ${formType.one}`
                    }
                    maxWidth="640px"
                >
                    <form onSubmit={formik.handleSubmit} noValidate>
                        <div className="admin-form-stack">
                            <FormInput
                                label="Banner Headline Title"
                                name="title"
                                required
                                formik={formik}
                                placeholder="e.g. Next-Gen Gaming Rigs on Sale"
                            />

                            <FormInput
                                label="Subtitle / Description"
                                name="subtitle"
                                formik={formik}
                                placeholder="e.g. Up to 15% off Intel Core i9 & RTX 4090 builds"
                            />

                            <div className="admin-form-grid-2">
                                {/* Where it shows; changing it moves it to
                                    the other list. */}
                                <Select
                                    label="Shows as"
                                    name="position"
                                    required
                                    formik={formik}
                                    options={[
                                        {
                                            value: 'hero',
                                            label: `Hero slide (${BANNER_TYPES.hero.size})`,
                                        },
                                        {
                                            value: 'promo_side',
                                            label: `Promo card (${BANNER_TYPES.promo.size})`,
                                        },
                                    ]}
                                />

                                <FormInput
                                    label="Badge Chip"
                                    name="badge"
                                    formik={formik}
                                    placeholder="e.g. FLASH SALE"
                                />
                            </div>

                            <div>
                                <label className="admin-form-field-label">
                                    Banner Image URL{' '}
                                    <span className="text-primary">*</span>
                                </label>
                                <div className="admin-input-row-flex">
                                    <input
                                        type="text"
                                        name="image_path"
                                        value={formik.values.image_path}
                                        onChange={formik.handleChange}
                                        placeholder={`Upload a ${formType.size} image`}
                                        className="auth-text-input admin-input-flex-1"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        icon={Crop}
                                        onClick={() => setCropperOpen(true)}
                                        loading={uploadingImage}
                                        disabled={uploadingImage}
                                    >
                                        {uploadingImage
                                            ? 'Uploading…'
                                            : 'Crop / Upload'}
                                    </Button>
                                </div>
                            </div>

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Target Link URL"
                                    name="link_url"
                                    required
                                    formik={formik}
                                    placeholder="/shop or /products/rtx-4090"
                                />

                                <FormInput
                                    label="Button Call-to-Action Text"
                                    name="button_text"
                                    required
                                    formik={formik}
                                    placeholder="Shop Now"
                                />
                            </div>

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label={`Order among ${formType.tab.toLowerCase()}`}
                                    name="sort_order"
                                    type="number"
                                    required
                                    formik={formik}
                                />

                                <div>
                                    <Checkbox
                                        name="is_active"
                                        label="Show on the homepage"
                                        checked={formik.values.is_active}
                                        onChange={formik.handleChange}
                                    />
                                </div>
                            </div>

                            <div className="admin-modal-action-row">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setModalOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={formik.isSubmitting}
                                >
                                    Save {formType.one}
                                </Button>
                            </div>
                        </div>
                    </form>
                </Modal>

                {/* Integrated Image Cropper Modal */}
                {cropperOpen && (
                    <ImageCropperModal
                        isOpen={cropperOpen}
                        onClose={() => setCropperOpen(false)}
                        onCropComplete={handleCropComplete}
                        aspectRatio={formType.aspect}
                        title={formType.cropTitle}
                    />
                )}
            </div>
        </AdminLayout>
    );
}
