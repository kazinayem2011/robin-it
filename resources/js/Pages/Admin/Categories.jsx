import React, { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { Layers, Plus } from 'lucide-react';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import SearchInput from '@/Components/SearchInput';
import { toast } from '@/Components/Toast';
import { adminCategorySchema } from '@/validations';
import { adminService } from '@/services';
import { siteConfig } from '@/constants';
import {
    CategoryParentCard,
    CategoryFormModal,
    CategoryDeleteModal,
} from './Components';

/**
 * Main Admin Category Hierarchy & Mega Menu Organizer Page
 */
export default function Categories({ categories = [], parentOptions = [] }) {
    const [searchQuery, setSearchQuery] = useState('');
    const [collapsedIds, setCollapsedIds] = useState(new Set());
    const [modalState, setModalState] = useState({
        isOpen: false,
        mode: 'create', // 'create' | 'edit'
        parentCategory: null,
        category: null,
        defaultLevel: 1,
    });
    const [deleteModalState, setDeleteModalState] = useState({
        isOpen: false,
        category: null,
    });
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Toggle collapse for parent cards
    const toggleCollapse = (id) => {
        setCollapsedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    // Expand all / Collapse all toggle
    const toggleAll = () => {
        if (collapsedIds.size > 0) {
            setCollapsedIds(new Set());
        } else {
            setCollapsedIds(new Set(categories.map((c) => c.id)));
        }
    };

    // Filter categories by search term
    const filteredCategories = useMemo(() => {
        if (!searchQuery.trim()) return categories;
        const q = searchQuery.toLowerCase();

        return categories
            .map((parent) => {
                const parentMatch = parent.name.toLowerCase().includes(q);
                const matchingChildren = (parent.children || [])
                    .map((sub) => {
                        const subMatch = sub.name.toLowerCase().includes(q);
                        const matchingL3 = (sub.children || []).filter(
                            (child) => child.name.toLowerCase().includes(q),
                        );
                        if (subMatch || matchingL3.length > 0) {
                            return {
                                ...sub,
                                children:
                                    matchingL3.length > 0
                                        ? matchingL3
                                        : sub.children,
                            };
                        }
                        return null;
                    })
                    .filter(Boolean);

                if (parentMatch || matchingChildren.length > 0) {
                    return {
                        ...parent,
                        children:
                            matchingChildren.length > 0
                                ? matchingChildren
                                : parent.children,
                    };
                }
                return null;
            })
            .filter(Boolean);
    }, [categories, searchQuery]);

    // Formik form for Create & Edit Category
    const formik = useFormik({
        initialValues: {
            name: '',
            slug: '',
            parent_id: '',
            icon: '',
            badge: '',
            is_offer: false,
            is_active: true,
        },
        validationSchema: adminCategorySchema,
        onSubmit: async (values) => {
            setIsSubmitting(true);
            try {
                const targetParentId = values.parent_id
                    ? Number(values.parent_id)
                    : modalState.parentCategory
                      ? modalState.parentCategory.id
                      : null;

                const payload = {
                    ...values,
                    parent_id: targetParentId,
                    badge: values.badge || null,
                    icon: values.icon || null,
                };

                if (modalState.mode === 'create') {
                    await adminService.createCategory(payload);
                    toast.success('Category created successfully!', 'Added');
                } else {
                    await adminService.updateCategory(
                        modalState.category.id,
                        payload,
                    );
                    toast.success('Category updated successfully!', 'Saved');
                }

                closeModal();
                router.reload({ preserveScroll: true });
            } catch (error) {
                console.error('Category action failed', error);
                toast.error(
                    error?.message || 'Failed to save category.',
                    'Error',
                );
            } finally {
                setIsSubmitting(false);
            }
        },
    });

    // Modal open handlers
    const openCreateRootModal = () => {
        formik.resetForm({
            values: {
                name: '',
                slug: '',
                parent_id: '',
                icon: 'Layers',
                badge: '',
                is_offer: false,
                is_active: true,
            },
        });
        setModalState({
            isOpen: true,
            mode: 'create',
            parentCategory: null,
            category: null,
            defaultLevel: 1,
        });
    };

    const openCreateChildModal = (parent, level) => {
        formik.resetForm({
            values: {
                name: '',
                slug: '',
                parent_id: parent.id,
                icon: level === 2 ? 'Folder' : '',
                badge: '',
                is_offer: false,
                is_active: true,
            },
        });
        setModalState({
            isOpen: true,
            mode: 'create',
            parentCategory: parent,
            category: null,
            defaultLevel: level,
        });
    };

    const openEditModal = (cat) => {
        formik.resetForm({
            values: {
                name: cat.name || '',
                slug: cat.slug || '',
                parent_id: cat.parent_id || '',
                icon: cat.icon || '',
                badge: cat.badge || '',
                is_offer: Boolean(cat.is_offer),
                is_active:
                    cat.is_active !== undefined ? Boolean(cat.is_active) : true,
            },
        });
        setModalState({
            isOpen: true,
            mode: 'edit',
            parentCategory: null,
            category: cat,
            defaultLevel: cat.parent_id ? 2 : 1,
        });
    };

    const closeModal = () => {
        setModalState({
            isOpen: false,
            mode: 'create',
            parentCategory: null,
            category: null,
            defaultLevel: 1,
        });
    };

    // Handle Delete
    const handleDelete = async () => {
        if (!deleteModalState.category) return;
        setIsSubmitting(true);
        try {
            await adminService.deleteCategory(deleteModalState.category.id);
            toast.success(
                `'${deleteModalState.category.name}' removed successfully.`,
                'Deleted',
            );
            setDeleteModalState({ isOpen: false, category: null });
            router.reload({ preserveScroll: true });
        } catch (error) {
            console.error('Delete failed', error);
            toast.error('Failed to delete category.', 'Error');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <AdminLayout
            title="Category Hierarchy & Mega Menu"
            subtitle={`Organize the 3-Level Category Architecture for ${siteConfig.name} Mega Menu & Taxonomy`}
        >
            <Head title={`Category Organizer — Admin ${siteConfig.name}`} />

            {/* Main Taxonomy Management Card */}
            <div className="admin-card">
                <div className="admin-card-header">
                    <div className="admin-card-title-group">
                        <h3 className="admin-card-title">
                            Catalog Taxonomy Tree
                        </h3>
                        <span className="admin-table-item-sub">
                            Manage mega menu & category hierarchy
                        </span>
                    </div>

                    <div className="admin-header-actions">
                        <SearchInput
                            value={searchQuery}
                            onSearch={setSearchQuery}
                            placeholder="Search categories..."
                        />

                        <Button variant="outline" size="sm" onClick={toggleAll}>
                            {collapsedIds.size > 0
                                ? 'Expand All'
                                : 'Collapse All'}
                        </Button>

                        <Button
                            variant="primary"
                            size="sm"
                            icon={Plus}
                            onClick={openCreateRootModal}
                        >
                            Add Root Category
                        </Button>
                    </div>
                </div>

                {filteredCategories.length === 0 ? (
                    <EmptyState
                        title="No Categories Found"
                        description={
                            searchQuery
                                ? `No category matching "${searchQuery}"`
                                : 'Get started by creating your first root category.'
                        }
                        icon={Layers}
                        actionText="Create Root Category"
                        onAction={openCreateRootModal}
                    />
                ) : (
                    <div className="admin-cat-tree-list">
                        {filteredCategories.map((parent) => (
                            <CategoryParentCard
                                key={parent.id}
                                parent={parent}
                                isCollapsed={collapsedIds.has(parent.id)}
                                onToggleCollapse={toggleCollapse}
                                onEdit={openEditModal}
                                onDelete={(cat) =>
                                    setDeleteModalState({
                                        isOpen: true,
                                        category: cat,
                                    })
                                }
                                onAddSubcategory={openCreateChildModal}
                                onAddChild={openCreateChildModal}
                            />
                        ))}
                    </div>
                )}
            </div>

            {/* Category Create / Edit Modal */}
            <CategoryFormModal
                modalState={modalState}
                onClose={closeModal}
                formik={formik}
                parentOptions={parentOptions}
                isSubmitting={isSubmitting}
            />

            {/* Delete Confirmation Modal */}
            <CategoryDeleteModal
                deleteModalState={deleteModalState}
                onClose={() =>
                    setDeleteModalState({ isOpen: false, category: null })
                }
                onConfirmDelete={handleDelete}
                isSubmitting={isSubmitting}
            />
        </AdminLayout>
    );
}
