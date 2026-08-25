import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import {
    Button,
    FormInput,
    FormSelect,
    ImageCropperModal,
    Modal,
    Checkbox,
    toast,
} from '../../Components';
import { adminService, uploadService } from '../../services';
import { adminBannerSchema } from '../../validations';
import { ROUTES } from '../../constants/endpoints';
import {
    Image as ImageIcon,
    Plus,
    Trash2,
    Edit3,
    ExternalLink,
    Check,
    X,
    Crop,
    Sliders,
} from 'lucide-react';

export default function AdminBanners({ banners = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [cropperOpen, setCropperOpen] = useState(false);
    const [uploadingImage, setUploadingImage] = useState(false);
    const [editingBanner, setEditingBanner] = useState(null);

    const formik = useFormik({
        initialValues: {
            title: '',
            subtitle: '',
            badge: 'NEW ARRIVAL',
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
                    toast.success('Banner updated successfully!');
                } else {
                    await adminService.createBanner(values);
                    toast.success('Banner created successfully!');
                }
                setModalOpen(false);
                router.reload({ only: ['banners'] });
            } catch (err) {
                toast.error(err?.message || 'Failed to save banner.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleOpenCreate = () => {
        setEditingBanner(null);
        formik.resetForm({
            values: {
                title: '',
                subtitle: '',
                badge: 'NEW ARRIVAL',
                image_path: '/images/hero_banner_beast_pc.jpg',
                link_url: '/shop',
                button_text: 'Shop Now',
                position: 'hero',
                sort_order: banners.length + 1,
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
                position: banner.position,
                sort_order: banner.sort_order || 1,
                is_active: !!banner.is_active,
            },
        });
        setModalOpen(true);
    };

    const handleDelete = async (id) => {
        if (!confirm('Are you sure you want to delete this banner?')) return;
        try {
            await adminService.deleteBanner(id);
            toast.success('Banner removed.');
            router.reload({ only: ['banners'] });
        } catch (err) {
            toast.error('Failed to delete banner.');
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
            title="Marketing Banners &amp; Sliders"
            subtitle="Manage Homepage Hero Carousel, Promotional Cards &amp; Popups"
        >
            <Head title="Admin Banners &amp; Sliders" />

            <div className="admin-page-container">
                {/* Header Action Bar */}
                <div className="admin-section-header-bar">
                    <div>
                        <h2 className="admin-section-heading">
                            Active Promotional Assets ({banners.length} Banners)
                        </h2>
                        <p className="admin-section-sub">
                            Configure responsive banner imagery, custom links,
                            and crop aspect ratios.
                        </p>
                    </div>
                    <Button
                        variant="primary"
                        icon={Plus}
                        onClick={handleOpenCreate}
                    >
                        Add New Banner
                    </Button>
                </div>

                {/* Banners Grid */}
                <div className="admin-banners-grid">
                    {banners.map((b) => (
                        <div key={b.id} className="admin-banner-card">
                            <div className="admin-banner-preview">
                                <img src={b.image_path} alt={b.title} />
                                <span
                                    className={`banner-pos-tag pos-${b.position}`}
                                >
                                    {b.position.toUpperCase()}
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
                                    <span>Order: #{b.sort_order}</span>
                                    <span>
                                        Status:{' '}
                                        {b.is_active ? 'Active' : 'Draft'}
                                    </span>
                                </div>
                            </div>
                            <div className="admin-banner-actions">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Edit3}
                                    onClick={() => handleOpenEdit(b)}
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="danger"
                                    size="sm"
                                    icon={Trash2}
                                    onClick={() => handleDelete(b.id)}
                                >
                                    Delete
                                </Button>
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
                            ? 'Edit Banner'
                            : 'Create Marketing Banner'
                    }
                    maxWidth="640px"
                >
                    <form onSubmit={formik.handleSubmit}>
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
                                <FormSelect
                                    label="Position Placement"
                                    name="position"
                                    required
                                    formik={formik}
                                    options={[
                                        {
                                            value: 'hero',
                                            label: 'Hero Carousel Slider (1920x600 / 16:9)',
                                        },
                                        {
                                            value: 'promo_side',
                                            label: 'Promo Side Card (600x400 / 4:3)',
                                        },
                                        {
                                            value: 'promo_top',
                                            label: 'Top Promotional Bar',
                                        },
                                        {
                                            value: 'popup',
                                            label: 'Flash Sale Popup',
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
                                        placeholder="/images/hero_banner_beast_pc.jpg"
                                        className="form-input admin-input-flex-1"
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
                                    label="Carousel Display Order #"
                                    name="sort_order"
                                    type="number"
                                    required
                                    formik={formik}
                                />

                                <div className="admin-checkbox-align-center">
                                    <Checkbox
                                        name="is_active"
                                        label="Active in Live Storefront"
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
                                    Save Banner
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
                        aspectRatio={16 / 9}
                        title="Crop Banner Graphic (16:9 HD)"
                    />
                )}
            </div>
        </AdminLayout>
    );
}
