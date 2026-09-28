<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Support\PcBuilderHealth;
use App\Support\PreorderLedger;
use App\Support\ProfitAndLoss;
use App\Support\QueueHealth;
use App\Support\SalesMargin;
use App\Support\ShopDate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive overview & KPI dashboard.
 */
class DashboardController extends Controller
{
    /** How many low-stock rows the overview panel shows before it stops. */
    private const LOW_STOCK_SHOWN = 8;

    public function index(Request $request): Response
    {
        // A storekeeper's job is deliveries and stock. Hiding the cards would
        // not be enough — Inertia ships its props in the page source — so the
        // shop's takings are never computed for someone who cannot see them.
        $seesMoney = $request->user()->can_('finance');

        $totalRevenue = (float) Order::whereNotIn('status', Order::TERMINAL_STATUSES)->sum('total');
        $totalOrders = Order::count();
        $pendingOrders = Order::whereIn('status', ['pending', 'processing', 'shipped'])->count();
        $totalCustomers = User::where('role', User::ROLE_CUSTOMER)->count();
        /*
         * The most urgent few, not all of them.
         *
         * This fetched every product at or below the threshold, with its brand
         * and all its images, and the page listed the lot. A shop that has just
         * counted its shelves down — or one whose stock ledger has been reset —
         * has every product under the threshold, and the panel grew to the
         * height of the catalogue: on this dataset a dashboard three and a half
         * thousand pixels tall, which is a list nobody reads rather than an
         * alert somebody acts on.
         *
         * Lowest stock first, so the cut keeps the ones that matter.
         */
        // What the shop stocks and is running out of — the same rule as the
        // Stock page. The whole catalogue counted, most of it never bought in.
        $lowStockCount = Product::needingReorder()->count();

        $lowStockProducts = Product::needingReorder()
            ->with(['brand', 'images'])
            ->orderBy('stock_quantity')
            ->orderBy('name')
            ->take(self::LOW_STOCK_SHOWN)
            ->get();

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
            // The real total, not the length of the list above it.
            'low_stock_count' => $lowStockCount,
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
            // So the panel can say how many it is not showing.
            'lowStockTotal' => $lowStockCount,
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
     * Open orders with something still owed — sold past stock, or a pre-order
     * whose delivery has not landed. They cannot ship until it arrives.
     */
    private function waitingForStock(): int
    {
        $ledger = app(PreorderLedger::class);

        return Order::whereIn('status', ['pending', 'processing'])
            ->whereIn('id', StockMovement::where('reference_type', Order::class)
                ->where('type', StockMovement::SALE)
                ->where('balance_after', '<', 0)
                ->select('reference_id'))
            ->with('items:id,order_id,product_id,product_variant_id')
            ->get(['id'])
            // Read afresh: a delivery since the last look ends the wait.
            ->each(fn (Order $order) => $ledger->forget($order->id))
            ->filter(fn (Order $order) => $order->items->contains(
                fn ($item) => $ledger->stillOwed($order->id, (int) $item->product_id, $item->product_variant_id ? (int) $item->product_variant_id : null)
            ))
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function needsAttention(Request $request): array
    {
        $user = $request->user();

        $items = [
            [
                'ability' => 'catalogue',
                'label' => 'Parts the builder cannot check',
                'hint' => 'Missing the specs compatibility reads',
                'count' => app(PcBuilderHealth::class)->summary()['spec_gaps'],
                'url' => '/admin/pc-builder',
                'tone' => 'warn',
            ],
            [
                'ability' => 'stock',
                'label' => 'Low stock',
                'hint' => 'At or below their reorder level',
                // Products the shop stocks; one never stocked is not "low".
                'count' => Product::needingReorder()->count(),
                'url' => '/admin/stock?reorder=1',
                'tone' => 'warn',
            ],
            [
                'ability' => 'orders',
                'label' => 'Waiting for stock',
                'hint' => 'Cannot ship until the stock arrives',
                'count' => $this->waitingForStock(),
                'url' => '/admin/orders',
                'tone' => 'warn',
            ],
            [
                'ability' => 'stock',
                'label' => 'Purchase orders overdue',
                'hint' => 'Past their expected date',
                'count' => PurchaseOrder::open()
                    ->whereNotNull('expected_on')
                    ->where('expected_on', '<', ShopDate::today())
                    ->count(),
                'url' => '/admin/purchase-orders',
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
                'hint' => 'Sold out of something you stock',
                // Ran out, not never stocked: a listing never bought in is
                // not news, and counting them buried the ones that matter.
                'count' => Product::where('is_active', true)->where('stock_quantity', '<=', 0)
                    ->whereIn('id', StockMovement::query()->select('product_id'))->count(),
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
