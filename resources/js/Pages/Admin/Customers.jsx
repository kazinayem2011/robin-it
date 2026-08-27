import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Users } from 'lucide-react';
import DataTable from '@/Components/DataTable';
import { formatBdt, formatDate, formatBdPhone } from '@/utils/formatters';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';

export default function Customers({ customers = { data: [] }, search = '' }) {
    const [searchTerm, setSearchTerm] = useState(search);

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
