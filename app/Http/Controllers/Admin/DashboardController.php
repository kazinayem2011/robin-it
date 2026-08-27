<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\QueueHealth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive overview & KPI dashboard.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $totalRevenue = (float) Order::where('status', '!=', 'cancelled')->sum('total');
        $totalOrders = Order::count();
        $pendingOrders = Order::whereIn('status', ['pending', 'processing', 'shipped'])->count();
        $totalCustomers = User::where('role', User::ROLE_CUSTOMER)->count();
        $lowStockProducts = Product::where('stock_quantity', '<=', 10)->with(['brand', 'images'])->get();

        $recentOrders = Order::with(['user', 'items.product'])
            ->latest()
            ->take(8)
            ->get();

        $metrics = [
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'total_customers' => $totalCustomers,
            'total_products' => Product::count(),
            'low_stock_count' => $lowStockProducts->count(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'metrics' => $metrics,
            'recentOrders' => $recentOrders,
            'lowStockProducts' => $lowStockProducts,
            // A dead queue worker means customers silently stop receiving
            // order emails. Nothing else in the app would say so.
            'queueHealth' => QueueHealth::check(),
        ]);
    }
}
