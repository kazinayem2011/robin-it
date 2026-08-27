import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    FileText,
    Plus,
    Edit2,
    Trash2,
    ExternalLink,
    Lock,
} from 'lucide-react';
import Button from '@/Components/Button';
import { Checkbox } from '@/Components/Checkbox';
import DataTable from '@/Components/DataTable';
import FormInput from '@/Components/FormInput';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminPageSchema } from '@/validations';

const empty = {
    slug: '',
    title: '',
    subtitle: '',
    body: '',
    meta_title: '',
    meta_description: '',
    is_published: true,
};

/**
 * The pages the shop writes itself.
 *
 * About, privacy, terms and the return policy were footer links with nothing
 * behind them, and About's words lived in the JSX — so changing a sentence
 * about the business needed a developer and a deploy.
 */
export default function AdminPages({ pages = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const formik = useFormik({
        initialValues: empty,
        validationSchema: adminPageSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                const data = editing
                    ? await adminService.updatePage(editing.id, values)
                    : await adminService.createPage(values);
                toast.success(data?.message || 'Saved.');
                setModalOpen(false);
                setEditing(null);
                resetForm({ values: empty });
                router.reload({ only: ['pages'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that page.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: empty });
        setModalOpen(true);
    };

    const openEdit = (page) => {
        setEditing(page);
        formik.resetForm({
            values: {
                slug: page.slug || '',
                title: page.title || '',
                subtitle: page.subtitle || '',
                body: page.body || '',
                meta_title: page.meta_title || '',
                meta_description: page.meta_description || '',
                is_published: page.is_published !== false,
            },
        });
        setModalOpen(true);
    };

    const remove = async (page) => {
        if (
            !confirm(`Delete "${page.title}"? Anything linking to it will 404.`)
        )
            return;
        try {
            const data = await adminService.deletePage(page.id);
            toast.success(data?.message || 'Removed.');
            router.reload({ only: ['pages'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that page.');
        }
    };

    const columns = [
        {
            key: 'title',
            header: 'Page',
            sortable: false,
            render: (p) => (
                <div>
                    <div className="admin-stock-product-name">
                        {p.title}
                        {p.is_system && (
                            <span
                                className="admin-supplier-retired"
                                title="Linked from the footer — editable, not removable"
                            >
                                <Lock size={10} /> Built in
                            </span>
                        )}
                        {!p.is_published && (
                            <span className="admin-supplier-retired">
                                Hidden
                            </span>
                        )}
                    </div>
                    <div className="admin-field-hint">{p.url}</div>
                </div>
            ),
        },
        {
            key: 'updated',
            header: 'Last edited',
            render: (p) => (
                <div>
                    <div>{p.updated_at ?? '—'}</div>
                    {p.updated_by_name && (
                        <div className="admin-field-hint">
                            by {p.updated_by_name}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (p) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <a
                        className="admin-table-icon-btn"
                        href={p.url}
                        target="_blank"
                        rel="noreferrer"
                        title="Open on the site"
                    >
                        <ExternalLink size={14} />
                    </a>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Edit"
                        onClick={() => openEdit(p)}
                    >
                        <Edit2 size={14} />
                    </button>
                    {/* Built-in pages are linked from the footer and expected
                        by law to exist; the words are the shop's, the page is
                        not theirs to delete. */}
                    {!p.is_system && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Delete"
                            onClick={() => remove(p)}
                        >
                            <Trash2 size={14} />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Pages"
            subtitle="About, privacy, terms and anything else you want to say"
        >
            <Head title="Pages" />

            <DataTable
                columns={columns}
                data={pages}
                title="Content pages"
                subtitle="Edited here, live on the site straight away"
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        New page
                    </Button>
                }
                emptyTitle="No pages yet"
                emptyIcon={FileText}
                pagination={false}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.title}` : 'New page'}
                maxWidth="760px"
                footer={
                    <div className="admin-input-row-flex admin-modal-actions">
                        <Button
                            variant="secondary"
                            onClick={() => setModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={formik.handleSubmit}
                            disabled={formik.isSubmitting}
                        >
                            {formik.isSubmitting ? 'Saving…' : 'Save page'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit} noValidate>
                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Title"
                            name="title"
                            formik={formik}
                            required
                            placeholder="Privacy policy"
                        />
                        <FormInput
                            label="Address"
                            name="slug"
                            formik={formik}
                            required
                            disabled={Boolean(editing?.is_system)}
                            placeholder="privacy"
                        />
                    </div>
                    {editing?.is_system && (
                        <span className="admin-field-hint">
                            The footer links to this page by name, so its
                            address cannot move.
                        </span>
                    )}

                    <FormInput
                        label="Subtitle"
                        name="subtitle"
                        formik={formik}
                        placeholder="What we collect, and what we do with it"
                    />

                    <FormInput
                        label="Body"
                        name="body"
                        type="textarea"
                        rows={14}
                        formik={formik}
                        required
                        placeholder="<p>Write in HTML. Headings, paragraphs, lists, links and tables are kept; scripts and anything else are stripped when you save.</p>"
                    />

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Search engine title"
                            name="meta_title"
                            formik={formik}
                            placeholder="Leave blank to use the title"
                        />
                        <FormInput
                            label="Search engine description"
                            name="meta_description"
                            formik={formik}
                            placeholder="One sentence for Google and shared links"
                        />
                    </div>

                    <Checkbox
                        name="is_published"
                        label="Visible on the site"
                        checked={formik.values.is_published}
                        onChange={formik.handleChange}
                    />
                </form>
            </Modal>
        </AdminLayout>
    );
}
