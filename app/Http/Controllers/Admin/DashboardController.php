<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\WarrantyClaim;
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

            /*
             * Work waiting for somebody, filling a row that held one card and
             * three gaps. Each is only counted for a viewer whose role covers
             * it — a storekeeper has no business knowing how many customers
             * have written in, and Inertia ships every prop in the page source.
             */
            'attention' => $this->needsAttention($request),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function needsAttention(Request $request): array
    {
        $user = $request->user();

        $items = [
            [
                'ability' => 'stock',
                'label' => 'Low stock',
                'hint' => 'At or below the threshold',
                'count' => Product::where('is_active', true)->where('stock_quantity', '<=', 10)->count(),
                'url' => '/admin/stock',
                'tone' => 'warn',
            ],
            [
                'ability' => 'orders',
                'label' => 'Awaiting dispatch',
                'hint' => 'Paid for, not yet with a courier',
                'count' => Order::whereIn('status', ['pending', 'processing'])->count(),
                'url' => '/admin/orders',
                'tone' => 'info',
            ],
            [
                'ability' => 'support',
                'label' => 'Unanswered messages',
                'hint' => 'Nobody has replied yet',
                'count' => ContactMessage::where('status', ContactMessage::STATUS_NEW)->count(),
                'url' => '/admin/messages',
                'tone' => 'info',
            ],
            [
                'ability' => 'support',
                'label' => 'Reviews to approve',
                'hint' => 'Written but not yet published',
                'count' => ProductReview::where('is_approved', false)->count(),
                'url' => '/admin/reviews',
                'tone' => 'info',
            ],
            [
                'ability' => 'support',
                'label' => 'Open warranty claims',
                'hint' => 'Not yet finished',
                'count' => WarrantyClaim::whereNotIn('status', ['completed', 'rejected'])->count(),
                'url' => '/admin/warranty',
                'tone' => 'info',
            ],
            [
                'ability' => 'catalogue',
                'label' => 'Out of stock',
                'hint' => 'Listed but unbuyable',
                'count' => Product::where('is_active', true)->where('stock_quantity', '<=', 0)->count(),
                'url' => '/admin/products',
                'tone' => 'warn',
            ],
        ];

        return collect($items)
            ->filter(fn ($item) => $user->can_($item['ability']))
            ->map(fn ($item) => collect($item)->except('ability')->all())
            ->values()
            ->all();
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
