<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\ProfitAndLoss;
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
            // Goods sold less what those goods cost. Not a P&L — orders whose
            // cost is not fully known are excluded rather than counted at a
            // partial cost.
            'margin' => $seesMoney ? SalesMargin::summary() : null,

            /*
             * This month's profit and loss, so the overview answers "are we
             * making money" without a trip to the report. Withheld along with
             * the rest of the takings from anyone without the finance
             * ability — Inertia ships its props in the page source.
             */
            'profitAndLoss' => $seesMoney ? $this->thisMonth() : null,
            'recentOrders' => $recentOrders,
            'lowStockProducts' => $lowStockProducts,
            // A dead queue worker means customers silently stop receiving
            // order emails. Nothing else in the app would say so.
            'queueHealth' => QueueHealth::check(),
        ]);
    }

    /**
     * The month so far, in the four numbers worth glancing at.
     *
     * The full statement is a wide object; the card wants a handful, and
     * sending the rest would put every expense category into the page source
     * of a screen that does not show them.
     */
    private function thisMonth(): array
    {
        $statement = ProfitAndLoss::statement(
            now()->startOfMonth()->toDateString(),
            now()->toDateString()
        );

        return [
            'from' => $statement['from'],
            'to' => $statement['to'],
            'revenue' => $statement['income']['total'] ?? 0,
            'cost_of_goods' => $statement['cost_of_goods'],
            'expenses' => $statement['expenses']['total'] ?? 0,
            'gross_profit' => $statement['gross_profit'],
            'net_profit' => $statement['net_profit'],
            'net_margin_percent' => $statement['net_margin_percent'],
            'orders_counted' => $statement['orders_counted'],
            /*
             * Orders left out for want of a known cost. Without this the card
             * reads "৳0 revenue" at a shop that sold nine hundred thousand
             * taka of hardware, which is true of the statement and a lie about
             * the month.
             */
            'excluded' => $statement['excluded'],
        ];
    }
}
