<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\QueueHealth;
use App\Support\SalesMargin;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive overview & KPI dashboard.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // A storekeeper's job is deliveries and stock. Hiding the cards would
        // not be enough — Inertia ships its props in the page source — so the
        // shop's takings are never computed for someone who cannot see them.
        $seesMoney = $request->user()->can_('finance');

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
            'total_revenue' => $seesMoney ? $totalRevenue : null,
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'total_customers' => $request->user()->can_('customers') ? $totalCustomers : null,
            'total_products' => Product::count(),
            'low_stock_count' => $lowStockProducts->count(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'metrics' => $metrics,
            // Goods sold less what those goods cost. Not a P&L — there are no
            // expense records yet — and orders whose cost is not fully known
            // are excluded rather than counted at a partial cost.
            'margin' => $seesMoney ? SalesMargin::summary() : null,
            'recentOrders' => $recentOrders,
            'lowStockProducts' => $lowStockProducts,
            // A dead queue worker means customers silently stop receiving
            // order emails. Nothing else in the app would say so.
            'queueHealth' => QueueHealth::check(),
        ]);
    }
}
