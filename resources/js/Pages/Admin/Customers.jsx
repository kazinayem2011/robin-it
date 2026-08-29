import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Users, Ban, RotateCcw } from 'lucide-react';
import DataTable from '@/Components/DataTable';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt, formatDate, formatBdPhone } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';
// Borrows the status badge from the campaigns screen.
import './Campaigns.css';

export default function Customers({ customers = { data: [] }, search = '' }) {
    const [searchTerm, setSearchTerm] = useState(search);
    const [working, setWorking] = useState(null);

    /**
     * Suspending a customer, or letting them back in.
     *
     * Deleting was the only way to stop somebody ordering, and it takes their
     * order history with it — which is exactly the record you want when the
     * reason for stopping them is a dispute.
     */
    const setActive = async (customer, active) => {
        if (
            !active &&
            !window.confirm(
                `Suspend ${customer.name}? They will be signed out and cannot sign in again until you restore them. Their orders are kept.`,
            )
        ) {
            return;
        }

        setWorking(customer.id);

        try {
            const res = await adminService.setCustomerActive(
                customer.id,
                active,
            );
            toast.success(res?.message || 'Done.');
            router.reload({ only: ['customers'] });
        } catch (err) {
            toast.error(err?.message || 'That did not work.');
        } finally {
            setWorking(null);
        }
    };

    const handleSearch = (term) => {
        setSearchTerm(term);
        router.get(
            ROUTES.ADMIN_CUSTOMERS,
            {
                search: term,
            },
            {
                preserveState: true,
            },
        );
    };

    const columns = [
        {
            key: 'customer',
            header: 'Customer Name',
            render: (c) => (
                <div className="admin-product-item-flex">
                    <div className="admin-customer-avatar">
                        {c.name ? c.name.charAt(0).toUpperCase() : 'U'}
                    </div>
                    <strong className="admin-customer-name">{c.name}</strong>
                </div>
            ),
        },
        {
            key: 'email',
            header: 'Email Address',
            render: (c) => (
                <span className="admin-customer-email">{c.email}</span>
            ),
        },
        {
            key: 'phone',
            header: 'Bangladeshi Mobile',
            render: (c) => (
                <span className="admin-customer-phone">
                    🇧🇩 {formatBdPhone(c.phone)}
                </span>
            ),
        },
        {
            key: 'orders',
            header: 'Total Orders',
            render: (c) => (
                <span className="admin-customer-orders-count">
                    {c.orders_count || 0} Order(s)
                </span>
            ),
        },
        {
            key: 'spent',
            header: 'Total Spent (BDT)',
            render: (c) => (
                <strong className="admin-customer-total-spent">
                    {formatBdt(c.orders_sum_total || 0)}
                </strong>
            ),
        },
        {
            key: 'date',
            header: 'Member Since',
            render: (c) => (
                <span className="admin-customer-date">
                    {formatDate(c.created_at)}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Access',
            render: (c) =>
                c.is_active === false ? (
                    <span className="cmp-badge cmp-failed">Suspended</span>
                ) : (
                    <span className="admin-field-hint">Can sign in</span>
                ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (c) => (
                <button
                    type="button"
                    className="admin-table-icon-btn"
                    disabled={working === c.id}
                    title={
                        c.is_active === false
                            ? 'Let them sign in again'
                            : 'Suspend this account'
                    }
                    onClick={() => setActive(c, c.is_active === false)}
                >
                    {c.is_active === false ? (
                        <RotateCcw size={14} />
                    ) : (
                        <Ban size={14} />
                    )}
                </button>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Customers Directory"
            subtitle={`Registered User Accounts & Nationwide Customer Volume for ${siteConfig.name}`}
        >
            <Head title={`Admin Customers — ${siteConfig.name}`} />

            {/* Reusable Data Table */}
            <DataTable
                columns={columns}
                data={customers}
                keyField="id"
                title="Customer Directory"
                subtitle="Nationwide customer volume, verified mobile contacts, and lifetime spent"
                searchable
                searchValue={searchTerm}
                onSearch={handleSearch}
                searchPlaceholder="Search by name, email, BD phone..."
                emptyIcon={Users}
                emptyTitle="No Customers Found"
                emptyDescription="Try searching with a different name, email, or mobile number."
                headerActions={
                    <div className="admin-customer-count-label">
                        Total Registered Customers:{' '}
                        <strong>
                            {customers.total || customers.data?.length || 0}
                        </strong>
                    </div>
                }
            />
        </AdminLayout>
    );
}
