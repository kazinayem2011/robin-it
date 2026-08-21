import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import {
    Button,
    DataTable,
    FormInput,
    FormSelect,
    Modal,
    ImageCropperModal,
    Checkbox,
    toast,
} from '../../Components';
import { adminService } from '../../services';
import { adminBlogSchema } from '../../validations';
import { ROUTES } from '../../constants/endpoints';
import {
    BookOpen,
    Plus,
    Trash2,
    Edit3,
    Clock,
    Crop,
    ExternalLink,
} from 'lucide-react';

const BLOG_CATEGORIES = [
    { value: 'Buying Guide', label: 'Buying Guide' },
    { value: 'Hardware Review', label: 'Hardware Review' },
    { value: 'Benchmark & Overclocking', label: 'Benchmark & Overclocking' },
    { value: 'PC Building Guide', label: 'PC Building Guide' },
    { value: 'Industry News', label: 'Industry News' },
];

export default function AdminBlogs({ blogs = [] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [cropperOpen, setCropperOpen] = useState(false);
    const [editingBlog, setEditingBlog] = useState(null);

    const formik = useFormik({
        initialValues: {
            title: '',
            category: 'Buying Guide',
            excerpt: '',
            content: '',
            image_path: '',
            author_name: 'Robin IT Lab',
            author_role: 'Lead Systems Engineer',
            read_time: '5 min read',
            is_published: true,
        },
        validationSchema: adminBlogSchema,
        enableReinitialize: true,
        onSubmit: async (values, { setSubmitting }) => {
            try {
                if (editingBlog) {
                    await adminService.updateBlog(editingBlog.id, values);
                    toast.success('Article updated successfully!');
                } else {
                    await adminService.createBlog(values);
                    toast.success('Article published successfully!');
                }
                setModalOpen(false);
                router.reload({ only: ['blogs'] });
            } catch (err) {
                toast.error(
                    err.response?.data?.message || 'Failed to save article.',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleOpenCreate = () => {
        setEditingBlog(null);
        formik.resetForm({
            values: {
                title: '',
                category: 'Buying Guide',
                excerpt: '',
                content: '',
                image_path: '/images/hero_banner_beast_pc.jpg',
                author_name: 'Robin IT Lab',
                author_role: 'Lead Systems Engineer',
                read_time: '5 min read',
                is_published: true,
            },
        });
        setModalOpen(true);
    };

    const handleOpenEdit = (blog) => {
        setEditingBlog(blog);
        formik.resetForm({
            values: {
                title: blog.title,
                category: blog.category || 'Buying Guide',
                excerpt: blog.excerpt || '',
                content: blog.content || '',
                image_path: blog.image_path,
                author_name: blog.author_name || 'Robin IT Lab',
                author_role: blog.author_role || '',
                read_time: blog.read_time || '5 min read',
                is_published: !!blog.is_published,
            },
        });
        setModalOpen(true);
    };

    const handleDelete = async (id) => {
        if (!confirm('Are you sure you want to delete this article?')) return;
        try {
            await adminService.deleteBlog(id);
            toast.success('Article removed.');
            router.reload({ only: ['blogs'] });
        } catch (err) {
            toast.error(
                err.response?.data?.message || 'Failed to delete article.',
            );
        }
    };

    const handleCropComplete = (croppedUrl) => {
        formik.setFieldValue('image_path', croppedUrl);
        setCropperOpen(false);
        toast.success('Article banner image cropped and updated.');
    };

    // Columns Definition for Reusable DataTable (SSOT)
    const columns = [
        {
            key: 'article',
            header: 'Article & Thumbnail',
            render: (blog) => (
                <div className="admin-table-item-cell">
                    <img
                        src={blog.image_path}
                        alt={blog.title}
                        className="admin-table-thumb"
                        onError={(e) => {
                            e.currentTarget.src =
                                '/images/hero_banner_beast_pc.jpg';
                        }}
                    />
                    <div className="min-w-0">
                        <strong className="admin-table-title-bold">
                            {blog.title}
                        </strong>
                        <span className="admin-table-desc-sub">
                            {blog.excerpt}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'category',
            header: 'Category',
            render: (blog) => (
                <span className="badge badge-info">{blog.category}</span>
            ),
        },
        {
            key: 'author',
            header: 'Author',
            render: (blog) => (
                <div>
                    <span className="font-semibold text-sm">
                        {blog.author_name}
                    </span>
                    {blog.author_role && (
                        <small className="text-muted block text-xs">
                            {blog.author_role}
                        </small>
                    )}
                </div>
            ),
        },
        {
            key: 'read_time',
            header: 'Read Time',
            render: (blog) => (
                <span className="admin-input-row-flex text-muted text-sm">
                    <Clock size={13} />
                    {blog.read_time}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (blog) => (
                <span
                    className={`status-pill ${blog.is_published ? 'active' : 'inactive'}`}
                >
                    {blog.is_published ? 'Published' : 'Draft'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (blog) => (
                <div className="admin-table-actions-right">
                    <a
                        href={ROUTES.BLOG_DETAIL(blog.slug)}
                        target="_blank"
                        rel="noreferrer"
                        className="btn btn-secondary btn-sm"
                        title="View on Storefront"
                    >
                        <ExternalLink size={14} />
                    </a>
                    <button
                        type="button"
                        onClick={() => handleOpenEdit(blog)}
                        className="btn btn-secondary btn-sm"
                        title="Edit Article"
                    >
                        <Edit3 size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={() => handleDelete(blog.id)}
                        className="btn btn-danger btn-sm"
                        title="Delete Article"
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Tech Journal &amp; Buying Guides"
            subtitle="Publish expert hardware benchmark reports, buying guides, and build analyses"
        >
            <Head title="Admin Tech Journal — Robin IT" />

            <div className="admin-page-container">
                {/* Standard Reusable DataTable Component */}
                <DataTable
                    title="Published Articles &amp; Guides"
                    subtitle="Technical hardware reviews and buying advice authored for Robin IT customers."
                    columns={columns}
                    data={blogs}
                    searchable
                    searchPlaceholder="Filter articles by title, category, author..."
                    emptyTitle="No Articles Found"
                    emptyDescription="You haven't written any Tech Journal articles yet."
                    emptyIcon={BookOpen}
                    emptyActionText="Write First Article"
                    onEmptyAction={handleOpenCreate}
                    headerActions={
                        <Button
                            variant="primary"
                            icon={Plus}
                            onClick={handleOpenCreate}
                        >
                            Write Article
                        </Button>
                    }
                />

                {/* Standard Reusable Modal Component */}
                <Modal
                    isOpen={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={
                        editingBlog
                            ? 'Edit Tech Journal Article'
                            : 'Publish New Tech Journal Article'
                    }
                    maxWidth="720px"
                >
                    <form onSubmit={formik.handleSubmit}>
                        <div className="admin-form-stack">
                            <FormInput
                                label="Article Headline Title"
                                name="title"
                                required
                                formik={formik}
                                placeholder="e.g. RTX 4090 vs RX 7900 XTX: 4K Ray Tracing Benchmark"
                            />

                            <div className="admin-form-grid-2">
                                <FormSelect
                                    label="Article Category"
                                    name="category"
                                    required
                                    formik={formik}
                                    options={BLOG_CATEGORIES}
                                />

                                <FormInput
                                    label="Estimated Read Time"
                                    name="read_time"
                                    required
                                    formik={formik}
                                    placeholder="e.g. 6 min read"
                                />
                            </div>

                            <FormInput
                                label="Short Excerpt / Summary"
                                name="excerpt"
                                type="textarea"
                                rows={2}
                                required
                                formik={formik}
                                placeholder="Brief 1-2 sentence overview for cards and social previews..."
                            />

                            <FormInput
                                label="Full Article Content (Markdown / Text)"
                                name="content"
                                type="textarea"
                                rows={6}
                                required
                                formik={formik}
                                placeholder="Write in-depth hardware analyses, test methodology, temperature metrics, and buying verdict..."
                            />

                            <div>
                                <label className="admin-form-field-label">
                                    Article Banner Thumbnail URL{' '}
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
                                    >
                                        Crop / Upload
                                    </Button>
                                </div>
                            </div>

                            <div className="admin-form-grid-2">
                                <FormInput
                                    label="Author Name"
                                    name="author_name"
                                    required
                                    formik={formik}
                                    placeholder="e.g. Robin IT Benchmark Lab"
                                />

                                <FormInput
                                    label="Author Role"
                                    name="author_role"
                                    formik={formik}
                                    placeholder="e.g. Lead Hardware Analyst"
                                />
                            </div>

                            <div>
                                <Checkbox
                                    name="is_published"
                                    label="Publish live on Storefront Tech Journal immediately"
                                    checked={formik.values.is_published}
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
                                    {editingBlog
                                        ? 'Update Article'
                                        : 'Publish Article'}
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
                        title="Crop Article Banner Graphic (16:9 1280x720)"
                    />
                )}
            </div>
        </AdminLayout>
    );
}
