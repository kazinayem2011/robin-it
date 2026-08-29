<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\BranchScope;
use App\Support\ProfitAndLoss;
use App\Support\Reports\CustomerReport;
use App\Support\Reports\DeliveryReport;
use App\Support\Reports\MoneyReport;
use App\Support\Reports\ProductReport;
use App\Support\Reports\SalesReport;
use App\Support\Reports\StockReport;
use App\Support\Reports\SupplierReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's reports.
 *
 * There was one — profit and loss — and nothing that answered the question
 * everybody asks first, which is how this month compares with the last. The
 * rest are grouped by the decision they serve rather than one page per figure:
 * somebody looking at what sold also wants to know which products earned and
 * who bought them, and making that three separate screens means nobody looks
 * at two of them.
 */
class ReportController extends Controller
{
    /** Where each report lives, for the index and the sidebar. */
    public const REPORTS = [
        ['key' => 'sales', 'title' => 'Sales', 'route' => '/admin/reports/sales',
            'blurb' => 'What sold and when, against the period before it. Products, and who bought them.'],
        ['key' => 'stock', 'title' => 'Stock', 'route' => '/admin/reports/stock',
            'blurb' => 'What is on the shelves, what it is worth, and how long it has sat there.'],
        ['key' => 'money', 'title' => 'Money', 'route' => '/admin/reports/money',
            'blurb' => 'What customers still owe, VAT for the return, and what went back.'],
        ['key' => 'delivery', 'title' => 'Delivery', 'route' => '/admin/reports/delivery',
            'blurb' => 'Which courier actually delivers, how often, and how fast.'],
        ['key' => 'suppliers', 'title' => 'Suppliers', 'route' => '/admin/reports/suppliers',
            'blurb' => 'Who sends what they promised, when they promised it.'],
        ['key' => 'profit', 'title' => 'Profit & loss', 'route' => '/admin/reports/profit-loss',
            'blurb' => 'Income less cost of goods and expenses, for a period.'],
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Reports/Index', ['reports' => self::REPORTS]);
    }

    public function sales(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Admin/Reports/Sales', [
            'sales' => SalesReport::for($from, $to),
            'products' => ProductReport::for($from, $to),
            'neverSold' => ProductReport::neverSold($from, $to),
            'customers' => CustomerReport::for($from, $to),
            'lapsed' => CustomerReport::lapsed(),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function stock(Request $request): Response
    {
        // A storekeeper sees their own branch; anyone unconfined sees the shop.
        $branch = BranchScope::narrow($request->user(), $request->integer('store') ?: null);

        return Inertia::render('Admin/Reports/Stock', [
            'valuation' => StockReport::valuation($branch),
            'ageing' => StockReport::ageing(60, $branch),
            'outOfStock' => StockReport::outOfStock(),
            'stores' => BranchScope::storesFor($request->user()),
            'filters' => ['store' => $branch],
        ]);
    }

    public function money(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Admin/Reports/Money', [
            // Owed is a position rather than a period: what is outstanding
            // today does not belong to a date range.
            'owed' => MoneyReport::owed(),
            'vat' => MoneyReport::vat($from, $to),
            'refunds' => MoneyReport::refunds($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function delivery(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Admin/Reports/Delivery', [
            'delivery' => DeliveryReport::for($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function suppliers(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Admin/Reports/Suppliers', [
            'suppliers' => SupplierReport::for($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function profitAndLoss(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Admin/Reports', [
            'statement' => ProfitAndLoss::statement($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * The period being asked about.
     *
     * This month so far unless told otherwise — the range somebody opening a
     * report most often wants, and the one they would have typed.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ], [
            'to.after_or_equal' => 'The end of the period cannot come before its start.',
        ]);

        return [
            $validated['from'] ?? now()->startOfMonth()->toDateString(),
            $validated['to'] ?? now()->toDateString(),
        ];
    }
}
