import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import Checkbox from '../../Components/Checkbox';
import DataTable from '../../Components/DataTable';
import FormInput from '../../Components/FormInput';
import ImageCropperModal from '../../Components/ImageCropperModal';
import Modal from '../../Components/Modal';
import { toast } from '../../Components/Toast';
import { adminService, uploadService } from '../../services';
import { adminOfferSchema } from '../../validations';
import { ROUTES } from '../../constants/endpoints';
import { offerWindow } from '../../utils/offerWindow';
import { Tag, Plus, Trash2, Edit3, Crop, ExternalLink } from 'lucide-react';

/**
 * The campaigns the shop runs.
 *
 * Not the discounted listing — that is every product whose price is cut, which
 * is worked out from the catalogue and needs no manager. This is the thing a
 * shop announces: a name, a window, the outlets it applies at, and terms.
 */

const BLANK = {
    title: '',
    excerpt: '',
    content: '',
    image_path: '',
    starts_at: '',
    ends_at: '',
    availability: 'All outlets',
    link_url: '',
    is_active: true,
    sort_order: 0,
};

/* <input type="datetime-local"> wants `YYYY-MM-DDTHH:mm`; the API sends ISO. */
const forInput = (value) => {
    if (!value) return '';

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) return '';

    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export default function AdminOffers({ offers = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [cropperOpen, setCropperOpen] = useState(false);
    const [uploadingImage, setUploadingImage] = useState(false);
    const [editing, setEditing] = useState(null);

    const formik = useFormik({
        initialValues: BLANK,
        validationSchema: adminOfferSchema,
        // No enableReinitialize: `initialValues` would be rebuilt every render
        // and reset the form over whatever handleOpenEdit had just loaded.
        onSubmit: async (values, { setSubmitting }) => {
            // Empty date boxes must go as null, not "", which is not a date.
            const payload = {
                ...values,
                starts_at: values.starts_at || null,
                ends_at: values.ends_at || null,
                sort_order: Number(values.sort_order) || 0,
            };

            try {
                if (editing) {
                    await adminService.updateOffer(editing.id, payload);
                    toast.success('Offer updated.');
                } else {
                    await adminService.createOffer(payload);
                    toast.success('Offer created.');
                }

                setModalOpen(false);
                router.reload({ only: ['offers'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that offer.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleOpenCreate = () => {
        setEditing(null);
        formik.resetForm({ values: BLANK });
        setModalOpen(true);
    };

    const handleOpenEdit = (offer) => {
        setEditing(offer);
        formik.resetForm({
            values: {
                title: offer.title || '',
                excerpt: offer.excerpt || '',
                content: offer.content || '',
                image_path: offer.image_path || '',
                starts_at: forInput(offer.starts_at),
                ends_at: forInput(offer.ends_at),
                availability: offer.availability || '',
                link_url: offer.link_url || '',
                is_active: !!offer.is_active,
                sort_order: offer.sort_order ?? 0,
            },
        });
        setModalOpen(true);
    };

    const handleDelete = async (id) => {
        if (!confirm('Delete this offer? Its page will stop resolving.'))
            return;

        try {
            await adminService.deleteOffer(id);
            toast.success('Offer removed.');
            router.reload({ only: ['offers'] });
        } catch (err) {
            toast.error(err?.message || 'Could not delete that offer.');
        }
    };

    const handleCropComplete = async ({ file }) => {
        setCropperOpen(false);
        setUploadingImage(true);

        try {
            const { path } = await uploadService.uploadImage(file, 'offers');

            formik.setFieldValue('image_path', path);
            toast.success('Banner uploaded.', 'Upload Complete');
        } catch (err) {
            toast.error(
                err?.message || 'Could not upload that image.',
                'Upload Failed',
            );
        } finally {
            setUploadingImage(false);
        }
    };

    const columns = [
        {
            key: 'offer',
            header: 'Offer',
            render: (offer) => (
                <div className="admin-table-item-cell">
                    {offer.image_path ? (
                        <img
                            src={offer.image_path}
                            alt={offer.title}
                            className="admin-table-thumb"
                        />
                    ) : (
                        <span className="admin-table-thumb admin-table-thumb-empty">
                            <Tag size={16} />
                        </span>
                    )}
                    <div className="min-w-0">
                        <strong className="admin-table-title-bold">
                            {offer.title}
                        </strong>
                        <span className="admin-table-desc-sub">
                            {offer.excerpt || '—'}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'when',
            header: 'Runs',
            render: (offer) => (
                <span className="admin-table-desc-sub">
                    {offerWindow(offer).range}
                </span>
            ),
        },
        {
            key: 'where',
            header: 'Where',
            render: (offer) => offer.availability || '—',
        },
        {
            key: 'status',
            header: 'Status',
            render: (offer) => {
                /*
                 * Two facts, not one: staff decide whether it is switched on,
                 * the dates decide whether it is on today. An offer can be
                 * live and not yet started, and "Active" alone would not say
                 * which of those a blank storefront meant.
                 */
                if (!offer.is_active) {
                    return <span className="badge badge-secondary">Off</span>;
                }

                const tone = {
                    running: 'badge-success',
                    upcoming: 'badge-info',
                    ended: 'badge-secondary',
                }[offer.status];

                return (
                    <span className={`badge ${tone}`}>
                        {offer.status === 'running'
                            ? 'Running'
                            : offer.status === 'upcoming'
                              ? 'Scheduled'
                              : 'Ended'}
                    </span>
                );
            },
        },
        {
            key: 'actions',
            header: 'Actions',
            render: (offer) => (
                <div className="admin-table-action-row">
                    <a
                        href={ROUTES.OFFER_DETAIL(offer.slug)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="btn btn-secondary btn-sm"
                        title="View on the storefront"
                    >
                        <ExternalLink size={14} />
                    </a>
                    <button
                        type="button"
                        onClick={() => handleOpenEdit(offer)}
                        className="btn btn-secondary btn-sm"
                        title="Edit offer"
                    >
                        <Edit3 size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={() => handleDelete(offer.id)}
                        className="btn btn-danger btn-sm"
                        title="Delete offer"
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Offers &amp; Campaigns"
            subtitle="The offers the shop announces — gift bundles, cashback and branch campaigns"
        >
            <Head title="Admin Offers" />

            <div className="admin-page-container">
                <DataTable
                    title="Offers"
                    subtitle="Each one has a window, the outlets it applies at, and a page of terms. Discounted products are a separate thing and need no entry here."
                    columns={columns}
                    data={offers}
                    searchable
                    searchPlaceholder="Filter offers by title, summary, outlet..."
                    emptyTitle="No offers yet"
                    emptyDescription="Announce a campaign — a gift bundle, cashback, or a branch opening."
                    emptyIcon={Tag}
                    emptyActionText="Create the first offer"
                    onEmptyAction={handleOpenCreate}
                    headerActions={
                        <Button
                            variant="primary"
                            icon={Plus}
                            onClick={handleOpenCreate}
                        >
                            New Offer
                        </Button>
                    }
                />

                <Modal
                    isOpen={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editing ? 'Edit Offer' : 'New Offer'}
                    maxWidth="720px"
                >
                    <form onSubmit={formik.handleSubmit} noValidate>
                        <div className="admin-form-stack">
                            <FormInput
                                label="Offer Title"
                                name="title"
                                required
                                formik={formik}
                                placeholder="e.g. Desktop PC Dhamaka Deal"
                            />

                            <FormInput
                                label="One-line Summary"
                                name="excerpt"
                                type="textarea"
                                rows={2}
                                formik={formik}
                                helperText="Shown under the title on the offers page."
                                placeholder="e.g. Buy a Desktop PC and get an exciting discount plus gifts!"
                            />

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Starts"
                                    name="starts_at"
                                    type="datetime-local"
                                    formik={formik}
                                    helperText="Leave blank for an offer that is already on."
                                />

                                <FormInput
                                    label="Ends"
                                    name="ends_at"
                                    type="datetime-local"
                                    formik={formik}
                                    helperText="Leave blank for a standing offer."
                                />
                            </div>

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Where It Applies"
                                    name="availability"
                                    formik={formik}
                                    placeholder="e.g. All outlets, Online only, Uttara branch"
                                />

                                <FormInput
                                    label="Order On The Page"
                                    name="sort_order"
                                    type="number"
                                    formik={formik}
                                    helperText="Lower shows first."
                                />
                            </div>

                            <FormInput
                                label="Terms &amp; Details"
                                name="content"
                                type="textarea"
                                rows={7}
                                formik={formik}
                                helperText="Shown on the offer's own page. Basic HTML is allowed."
                                placeholder="What the customer gets, which products qualify, and any conditions..."
                            />

                            <div>
                                <label className="admin-form-field-label">
                                    Offer Banner
                                </label>
                                <div className="admin-input-row-flex">
                                    <input
                                        type="text"
                                        name="image_path"
                                        value={formik.values.image_path}
                                        onChange={formik.handleChange}
                                        placeholder="/images/offers/desktop-deal.jpg"
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

                            <FormInput
                                label="Where “See the products” goes"
                                name="link_url"
                                formik={formik}
                                helperText="Optional. A category, a search, anywhere the qualifying products are."
                                placeholder="/shop/desktop"
                            />

                            <div>
                                <Checkbox
                                    name="is_active"
                                    label="Live on the storefront (within its dates)"
                                    checked={formik.values.is_active}
                                    onChange={formik.handleChange}
                                />
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
                                    {editing ? 'Update Offer' : 'Create Offer'}
                                </Button>
                            </div>
                        </div>
                    </form>
                </Modal>

                {cropperOpen && (
                    <ImageCropperModal
                        isOpen={cropperOpen}
                        onClose={() => setCropperOpen(false)}
                        onCropComplete={handleCropComplete}
                        /* Landscape: on the storefront these are banners
                           the width of a promo tile, not square posters. */
                        aspectRatio={16 / 9}
                        title="Crop Offer Banner (16:9, 1280x720)"
                    />
                )}
            </div>
        </AdminLayout>
    );
}
