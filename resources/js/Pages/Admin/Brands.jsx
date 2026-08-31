import React, { useState, useRef, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import Checkbox from '../../Components/Checkbox';
import DataTable from '../../Components/DataTable';
import FormInput from '../../Components/FormInput';
import Modal from '../../Components/Modal';
import BrandMark from '../../Components/BrandMark';
import { toast } from '../../Components/Toast';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import { uploadService } from '../../services';
import { Tag, Plus, Edit2, Trash2, Upload, Star } from 'lucide-react';

/**
 * The brands the shop stocks.
 *
 * There was no screen for this: the brands existed because a seeder made them
 * and nothing could add another. The logo matters beyond this page — the mega
 * menu shows a brand's logo where there is one and falls back to a lettermark
 * otherwise, and nothing could write a logo, so every one of the eleven hundred
 * brand entries in that menu is currently a lettermark.
 */
export default function AdminBrands({
    brands = { data: [] },
    filters = {},
    counts = {},
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [form, setForm] = useState({
        name: '',
        logo_path: '',
        is_featured: false,
    });
    const [search, setSearch] = useState(filters.search || '');
    const fileRef = useRef(null);
    const searchTimer = useRef(null);

    useEffect(() => () => clearTimeout(searchTimer.current), []);

    // Debounced, like the product search: a request per keystroke reorders
    // itself on a slow connection and leaves the wrong list on screen.
    const onSearch = (term) => {
        setSearch(term);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => {
            router.get(
                API_ENDPOINTS.ADMIN.BRANDS,
                { search: term },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 350);
    };

    const openCreate = () => {
        setEditing(null);
        setForm({ name: '', logo_path: '', is_featured: false });
        setModalOpen(true);
    };

    const openEdit = (brand) => {
        setEditing(brand);
        setForm({
            name: brand.name || '',
            logo_path: brand.logo_path || '',
            is_featured: Boolean(brand.is_featured),
        });
        setModalOpen(true);
    };

    const pickLogo = async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setUploading(true);
        try {
            const path = await uploadService.uploadImage(file, 'brands');
            setForm((f) => ({ ...f, logo_path: path }));
            toast.success('Logo uploaded.');
        } catch (error) {
            toast.error(error?.message || 'Could not upload that image.');
        } finally {
            setUploading(false);
            if (fileRef.current) fileRef.current.value = '';
        }
    };

    const save = async () => {
        if (!form.name.trim()) {
            toast.error('A brand needs a name.');
            return;
        }

        setBusyId('save');
        try {
            if (editing) {
                await axiosInstance.patch(
                    API_ENDPOINTS.ADMIN.BRAND_ITEM(editing.id),
                    form,
                );
                toast.success(`Brand "${form.name}" updated.`);
            } else {
                await axiosInstance.post(API_ENDPOINTS.ADMIN.BRANDS, form);
                toast.success(`Brand "${form.name}" created.`);
            }
            setModalOpen(false);
            router.reload({ only: ['brands', 'counts'] });
        } catch (error) {
            toast.error(error?.message || 'Could not save that brand.');
        } finally {
            setBusyId(null);
        }
    };

    const remove = async (brand) => {
        // Says what happens to the stock, because "delete" on a brand reads
        // like it might take the products with it. It does not.
        const warning =
            brand.products_count > 0
                ? `Delete "${brand.name}"? ${brand.products_count} product(s) will keep their stock but lose their brand.`
                : `Delete "${brand.name}"?`;

        if (!window.confirm(warning)) return;

        setBusyId(brand.id);
        try {
            await axiosInstance.delete(
                API_ENDPOINTS.ADMIN.BRAND_ITEM(brand.id),
            );
            toast.success('Brand deleted.');
            router.reload({ only: ['brands', 'counts'] });
        } catch (error) {
            toast.error(error?.message || 'Could not delete that brand.');
        } finally {
            setBusyId(null);
        }
    };

    const columns = [
        {
            key: 'brand',
            header: 'Brand',
            render: (b) => (
                <div className="admin-brand-cell">
                    <BrandMark name={b.name} logo={b.logo_path} size={30} />
                    <div>
                        <strong>{b.name}</strong>
                        <small>{b.slug}</small>
                    </div>
                </div>
            ),
        },
        {
            key: 'logo',
            header: 'Logo',
            render: (b) =>
                b.logo_path ? (
                    <span className="badge badge-new">Uploaded</span>
                ) : (
                    // Named rather than left blank: this is the reason the mega
                    // menu shows letters instead of a logo.
                    <span className="admin-field-hint">Lettermark in menu</span>
                ),
        },
        {
            key: 'products',
            header: 'Products',
            render: (b) => b.products_count ?? 0,
        },
        {
            key: 'featured',
            header: 'Featured',
            render: (b) =>
                b.is_featured ? (
                    <span className="badge badge-new">
                        <Star size={11} /> Featured
                    </span>
                ) : (
                    '—'
                ),
        },
        {
            key: 'actions',
            header: 'Actions',
            render: (b) => (
                <div className="admin-brand-actions">
                    <Button
                        variant="outline"
                        size="sm"
                        icon={Edit2}
                        disabled={busyId === b.id}
                        onClick={() => openEdit(b)}
                    >
                        Edit
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        icon={Trash2}
                        disabled={busyId === b.id}
                        onClick={() => remove(b)}
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Brands"
            subtitle="The makes you stock, and the logos that appear in the menu"
        >
            <Head title="Brands" />

            <div className="admin-page-container">
                <div className="admin-brand-toolbar">
                    <Button icon={Plus} onClick={openCreate}>
                        Add brand
                    </Button>
                    <span className="admin-field-hint">
                        {counts.total ?? 0} brands · {counts.withLogo ?? 0} with
                        a logo · {counts.featured ?? 0} featured
                    </span>
                </div>

                <DataTable
                    title="Brands"
                    columns={columns}
                    data={brands}
                    searchable
                    searchValue={search}
                    onSearch={onSearch}
                    searchPlaceholder="Search brands..."
                    emptyIcon={Tag}
                    emptyTitle="No brands yet"
                    emptyDescription="Add the makes you stock so products can be filed under them."
                />
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add brand'}
                maxWidth="520px"
            >
                <FormInput
                    id="brand_name"
                    name="name"
                    label="Brand Name"
                    required
                    value={form.name}
                    onChange={(e) =>
                        setForm((f) => ({ ...f, name: e.target.value }))
                    }
                    placeholder="ASUS"
                    helperText="Must match the name used in the category tree for the logo to appear in the menu."
                />

                <div className="auth-form-group">
                    <label className="auth-label">Logo</label>
                    <div className="admin-brand-logo-row">
                        <BrandMark
                            name={form.name || '?'}
                            logo={form.logo_path}
                            size={46}
                        />
                        <div className="admin-brand-logo-actions">
                            <Button
                                type="button"
                                variant="secondary"
                                icon={Upload}
                                loading={uploading}
                                onClick={() => fileRef.current?.click()}
                            >
                                {form.logo_path ? 'Replace' : 'Upload'}
                            </Button>
                            {form.logo_path && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setForm((f) => ({
                                            ...f,
                                            logo_path: '',
                                        }))
                                    }
                                >
                                    Remove
                                </Button>
                            )}
                        </div>
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/*"
                            hidden
                            onChange={pickLogo}
                        />
                    </div>
                    <span className="admin-field-hint">
                        Square works best. Without one, the menu shows the
                        brand&apos;s initials.
                    </span>
                </div>

                <Checkbox
                    id="is_featured"
                    name="is_featured"
                    label="Show on the storefront brand strip"
                    checked={form.is_featured}
                    onChange={(e) =>
                        setForm((f) => ({
                            ...f,
                            is_featured: e.target.checked,
                        }))
                    }
                />

                <div className="admin-modal-actions">
                    <Button
                        variant="secondary"
                        onClick={() => setModalOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button loading={busyId === 'save'} onClick={save}>
                        {editing ? 'Save changes' : 'Create brand'}
                    </Button>
                </div>
            </Modal>
        </AdminLayout>
    );
}
