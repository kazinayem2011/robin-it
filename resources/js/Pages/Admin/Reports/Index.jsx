import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    LineChart,
    Boxes,
    Wallet,
    Truck,
    Factory,
    TrendingUp,
    ArrowRight,
} from 'lucide-react';
import './Reports.css';

const ICONS = {
    sales: TrendingUp,
    stock: Boxes,
    money: Wallet,
    delivery: Truck,
    suppliers: Factory,
    profit: LineChart,
};

/**
 * Every report, in one place.
 *
 * Grouped by the decision each one serves rather than one page per figure:
 * somebody looking at what sold also wants to know which products earned and
 * who bought them, and making that three separate screens means nobody opens
 * two of them.
 */
export default function ReportsIndex({ reports = [] }) {
    return (
        <AdminLayout
            title="Reports"
            subtitle="What sold, what is sitting still, and what is owed"
        >
            <Head title="Reports" />

            <div className="rep-index">
                {reports.map((report) => {
                    const Icon = ICONS[report.key] ?? LineChart;

                    return (
                        <Link
                            key={report.key}
                            href={report.route}
                            className="rep-index-card"
                        >
                            <span className="rep-index-icon">
                                <Icon size={20} />
                            </span>
                            <h3>{report.title}</h3>
                            <p>{report.blurb}</p>
                            <span className="rep-index-go">
                                Open <ArrowRight size={14} />
                            </span>
                        </Link>
                    );
                })}
            </div>
        </AdminLayout>
    );
}
