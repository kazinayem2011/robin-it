import React, { useState, useMemo, useEffect, useRef } from 'react';
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
import { reorderSiblings, indexOnShelf } from '@/utils/reorderTree';
import { siteConfig } from '@/constants';
import {
    CategoryParentCard,
    CategoryFormModal,
    CategoryDeleteModal,
} from './Components';

/**
 * Main Admin Category Hierarchy & Mega Menu Organizer Page
 */
export default function Categories({
    categories = [],
    parentOptions = [],
    brandOptions = [],
}) {
    const [searchQuery, setSearchQuery] = useState('');

    /*
     * A local copy of the tree, so a drag can rearrange it under the cursor.
     *
     * `categories` is Inertia's prop and only changes when the server answers.
     * Waiting for that would mean the card snaps back to where it came from
     * and then jumps to where it was dropped, once per drop. The copy is
     * replaced whenever the prop does, so the server stays the authority.
     */
    const [tree, setTree] = useState(categories);

    useEffect(() => {
        setTree(categories);
    }, [categories]);
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
        if (!searchQuery.trim()) return tree;
        const q = searchQuery.toLowerCase();

        return tree
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
    }, [tree, searchQuery]);

    // Formik form for Create & Edit Category
    const formik = useFormik({
        initialValues: {
            name: '',
            slug: '',
            parent_id: '',
            brand_id: '',
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
                brand_id: '',
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
                brand_id: '',
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

    /*
     * Move a shelf one place among its siblings.
     *
     * Reloads only the tree rather than the whole page: `preserveScroll` keeps
     * the admin where they were, which matters when the shelf being moved is
     * two thirds of the way down a list of fifteen.
     */
    /*
     * Reordering is off while a search is filtering the tree. The list is a
     * subset then, so first and last in it are not first and last among the
     * siblings — the arrows would be enabled at the wrong ends and the move
     * would be against rows that are not on screen.
     */
    const canReorder = !searchQuery.trim();

    const reorderFailed = (err) => {
        toast.error(
            err?.message || 'Could not move that category.',
            'Reorder Failed',
        );
        /* Put the shelf back the way the server still has it. */
        setTree(categories);
    };

    const moveCategory = async (cat, direction) => {
        try {
            await adminService.moveCategory(cat.id, direction);
            router.reload({ only: ['categories'], preserveScroll: true });
        } catch (err) {
            reorderFailed(err);
        }
    };

    /*
     * Dragging a card to a place on its shelf.
     *
     * Held in a ref rather than state because it changes on every pointer
     * move across a row, and none of it is drawn — the only thing the page
     * renders from a drag is the rearranged list itself, and `draggingId`,
     * which is what fades the card being carried.
     */
    const dragRef = useRef(null);
    const [draggingId, setDraggingId] = useState(null);

    const startDrag = (cat, parentId) => {
        const from = indexOnShelf(tree, parentId, cat.id);
        if (from === -1) return;

        dragRef.current = { id: cat.id, parentId, from, to: from };
        setDraggingId(cat.id);
    };

    /*
     * Crossing a row rearranges the list immediately, so the shelf reads the
     * way it will end up rather than the way it started. Only the drop is
     * sent to the server.
     */
    const dragOver = (parentId, index) => {
        const drag = dragRef.current;

        /*
         * A card belongs to one shelf. Positions are per parent, so dragging
         * a subcategory over another parent's rows means nothing, and the
         * page ignores it rather than re-parenting something by accident.
         */
        if (!drag || drag.parentId !== parentId || drag.to === index) return;

        setTree((current) => {
            const at = indexOnShelf(current, parentId, drag.id);
            if (at === -1 || at === index) return current;

            drag.to = index;
            return reorderSiblings(current, parentId, at, index);
        });
    };

    const drop = async () => {
        const drag = dragRef.current;
        dragRef.current = null;
        setDraggingId(null);

        if (!drag || drag.to === drag.from) return;

        try {
            await adminService.moveCategoryTo(drag.id, drag.to);
            router.reload({ only: ['categories'], preserveScroll: true });
        } catch (err) {
            reorderFailed(err);
        }
    };

    /*
     * Fires whether the card was dropped or the drag was abandoned — on Esc,
     * or outside the list. `drop` clears the ref, so anything still in it here
     * was abandoned, and the preview has to be put back.
     */
    const endDrag = () => {
        if (!dragRef.current) return;

        dragRef.current = null;
        setDraggingId(null);
        setTree(categories);
    };

    const openEditModal = (cat) => {
        formik.resetForm({
            values: {
                name: cat.name || '',
                slug: cat.slug || '',
                parent_id: cat.parent_id || '',
                brand_id: cat.brand_id || '',
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
                        {filteredCategories.map((parent, index) => (
                            <CategoryParentCard
                                key={parent.id}
                                parent={parent}
                                onMove={canReorder ? moveCategory : null}
                                index={index}
                                isFirst={index === 0}
                                isLast={index === filteredCategories.length - 1}
                                draggingId={draggingId}
                                onDragStart={canReorder ? startDrag : null}
                                onDragEnterRow={dragOver}
                                onDrop={drop}
                                onDragEnd={endDrag}
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
                brandOptions={brandOptions}
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
