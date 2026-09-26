<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who asked to be told when something sold out comes back.
 *
 * Customers could ask ("Notify me") and were emailed when stock returned, but
 * nobody at the shop could see the list — so the plainest signal of what to
 * order next, a queue of people wanting one thing, was invisible. This shows
 * it, most-wanted first.
 */
class StockRequestController extends Controller
{
    /** How many addresses a row carries; the count says how many more. */
    private const EMAILS_PER_ROW = 50;

    public function index(Request $request): Response
    {
        $showTold = $request->boolean('told');

        $groups = StockNotification::query()
            ->select([
                'product_id',
                'product_variant_id',
                DB::raw('SUM(CASE WHEN notified_at IS NULL THEN 1 ELSE 0 END) as waiting'),
                DB::raw('SUM(CASE WHEN notified_at IS NULL THEN 0 ELSE 1 END) as told'),
                DB::raw('MIN(CASE WHEN notified_at IS NULL THEN created_at END) as oldest_waiting'),
                DB::raw('MAX(created_at) as latest'),
            ])
            ->groupBy('product_id', 'product_variant_id')
            ->when(! $showTold, fn ($q) => $q->havingRaw('SUM(CASE WHEN notified_at IS NULL THEN 1 ELSE 0 END) > 0'))
            ->orderByDesc('waiting')
            ->orderBy('oldest_waiting')
            ->with(['product:id,name,slug,stock_quantity,is_active', 'variant:id,name,stock_quantity'])
            ->get();

        $rows = $groups->map(function (StockNotification $group) {
            $requests = StockNotification::query()
                ->where('product_id', $group->product_id)
                ->when(
                    $group->product_variant_id,
                    fn ($q) => $q->where('product_variant_id', $group->product_variant_id),
                    fn ($q) => $q->whereNull('product_variant_id'),
                )
                ->orderByRaw('notified_at IS NOT NULL')
                ->latest('id')
                ->limit(self::EMAILS_PER_ROW)
                ->get(['email', 'phone', 'user_id', 'created_at', 'notified_at']);

            return [
                'key' => $group->product_id.':'.($group->product_variant_id ?? '-'),
                'product' => $group->product?->name ?? 'Removed product',
                'option' => $group->variant?->name,
                'slug' => $group->product?->slug,
                'stock' => (int) ($group->variant?->stock_quantity ?? $group->product?->stock_quantity ?? 0),
                'waiting' => (int) $group->waiting,
                'told' => (int) $group->told,
                'oldest_waiting' => $group->oldest_waiting ? date('d M Y', strtotime($group->oldest_waiting)) : null,
                'latest' => date('d M Y', strtotime($group->latest)),
                'requests' => $requests->map(fn ($r) => [
                    'contact' => $r->email ?? $r->phone,
                    'by' => $r->email ? 'email' : 'text',
                    'has_account' => (bool) $r->user_id,
                    'asked' => $r->created_at?->format('d M Y, g:i a'),
                    'told' => $r->notified_at?->format('d M Y'),
                ])->all(),
            ];
        })->values();

        return Inertia::render('Admin/Stock/Requests', [
            'rows' => $rows,
            'showTold' => $showTold,
            'totals' => [
                'waiting' => StockNotification::pending()->count(),
                // Distinct pairs, counted in PHP: string concatenation is
                // spelled differently on SQLite and MariaDB (where || is OR).
                'products' => StockNotification::pending()
                    ->select('product_id', 'product_variant_id')
                    ->distinct()
                    ->get()
                    ->count(),
            ],
        ]);
    }
}
