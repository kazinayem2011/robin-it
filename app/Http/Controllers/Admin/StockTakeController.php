<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\Store;
use App\Services\SerialService;
use App\Services\StockService;
use App\Services\StockTakeService;
use App\Support\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Counting the shelves, and the record of every correction made to them.
 */
class StockTakeController extends Controller
{
    public function __construct(private readonly StockTakeService $takes) {}

    /**
     * Record serials against stock already on the shelf.
     *
     * Serials could only ever arrive with a delivery, which left a shop that
     * started recording them part way through with no way to write down what
     * it already had.
     */
    public function storeSerials(Request $request, SerialService $serials): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'store_id' => 'nullable|integer|exists:stores,id',
            'serials' => 'required|array|min:1|max:200',
            'serials.*' => 'required|string|max:100',
        ]);

        $storeId = BranchScope::narrow($request->user(), $data['store_id'] ?? null);

        if (! BranchScope::allows($request->user(), $storeId)) {
            return $this->errorResponse(
                'You can only record serials for your own branch.',
                403,
                ApiCode::FORBIDDEN
            );
        }

        $result = $serials->addToStock(
            Product::findOrFail($data['product_id']),
            $data['product_variant_id'] ?? null,
            $data['serials'],
            $storeId
        );

        $message = "{$result['added']} serial".($result['added'] === 1 ? '' : 's').' recorded.';

        if ($result['skipped']) {
            $message .= ' '.count($result['skipped']).' already on the books: '
                .implode(', ', array_slice($result['skipped'], 0, 5)).'.';
        }

        return $this->successResponse($result, $message);
    }

    /** Correct a serial that was typed wrong, or delete one recorded in error. */
    public function updateSerial(Request $request, SerialService $serials, int $id): JsonResponse
    {
        $serial = ProductSerial::findOrFail($id);

        if (! BranchScope::allows($request->user(), $serial->store_id)) {
            return $this->errorResponse(
                'That unit belongs to another branch.',
                403,
                ApiCode::FORBIDDEN
            );
        }

        $data = $request->validate([
            'serial' => 'required|string|max:100',
            'reason' => 'nullable|string|max:255',
        ]);

        $was = $serial->serial;
        $serials->correct($serial, $data['serial'], $data['reason'] ?? null);

        return $this->successResponse(
            ['serial' => $serial->serial],
            "Corrected {$was} to {$serial->serial}."
        );
    }

    public function destroySerial(Request $request, SerialService $serials, int $id): JsonResponse
    {
        $serial = ProductSerial::findOrFail($id);

        if (! BranchScope::allows($request->user(), $serial->store_id)) {
            return $this->errorResponse(
                'That unit belongs to another branch.',
                403,
                ApiCode::FORBIDDEN
            );
        }

        $was = $serial->serial;
        $serials->remove($serial);

        return $this->successResponse([], "Serial {$was} removed.");
    }

    /** The count sheet for one branch. */
    public function create(Request $request): Response
    {
        $stores = BranchScope::storesFor($request->user());
        $storeId = BranchScope::narrow($request->user(), $request->integer('store') ?: null)
            ?: $stores->first()?->id;

        $store = $storeId ? Store::find($storeId) : null;
        $search = trim((string) $request->query('search', ''));

        return Inertia::render('Admin/Stock/Count', [
            'store' => $store?->only(['id', 'name', 'city']),
            'stores' => $stores,
            'branch' => BranchScope::name($request->user()),
            'filters' => ['search' => $search],
            'lines' => $store ? $this->takes->sheetFor($store, $search) : [],
            'recent' => StockTake::with('store:id,name')
                ->when(BranchScope::for($request->user()), fn ($q, $id) => $q->where('store_id', $id))
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (StockTake $t) => [
                    ...$t->only(['id', 'reference', 'lines_counted', 'lines_changed', 'net_units', 'value_change', 'counted_by_name']),
                    'store' => $t->store?->name,
                    'when' => $t->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.counted_quantity' => 'required|integer|min:0|max:1000000',
        ], [
            'lines.required' => 'Count at least one product before saving.',
            'lines.*.counted_quantity.min' => 'A count cannot be negative. Zero means none on the shelf.',
        ]);

        if (! BranchScope::allows($request->user(), (int) $validated['store_id'])) {
            return $this->errorResponse(
                'You can only count '.BranchScope::name($request->user()).'.',
                403,
                ApiCode::FORBIDDEN
            );
        }

        $take = $this->takes->apply(
            Store::findOrFail($validated['store_id']),
            $request->user(),
            $validated['lines'],
            $validated['note'] ?? null
        );

        $message = $take->lines_changed === 0
            ? "Counted {$take->lines_counted} product(s) at {$take->store->name} — everything matched the books."
            : "Counted {$take->lines_counted}, corrected {$take->lines_changed} ({$take->reference}).";

        return $this->successResponse(
            $take->only(['id', 'reference', 'lines_counted', 'lines_changed', 'net_units', 'value_change']),
            $message,
            201
        );
    }

    /**
     * Every unit the shop tracks by serial, and where it is.
     *
     * The question this answers is "is this ours, and who has it" — asked at
     * the counter when somebody walks in with a dead card and a claim.
     */
    public function serials(Request $request): Response
    {
        $branch = BranchScope::for($request->user());
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $serials = ProductSerial::query()
            ->with(['product:id,name', 'variant:id,name', 'order:id,order_number', 'store:id,name'])
            ->when($branch, fn ($q) => $q->where('store_id', $branch))
            ->when(array_key_exists($status, ProductSerial::STATUSES), fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $term = '%'.$search.'%';
                $w->where('serial', 'like', $term)
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $term))
                    ->orWhereHas('order', fn ($oq) => $oq->where('order_number', 'like', $term));
            }))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ProductSerial $s) => [
                'id' => $s->id,
                'serial' => $s->serial,
                'product' => $s->variant
                    ? "{$s->product?->name} ({$s->variant->name})"
                    : ($s->product?->name ?? 'Removed product'),
                'status' => $s->status,
                'status_label' => $s->status_label,
                'store' => $s->store?->name,
                'order_number' => $s->order?->order_number,
                'sold_at' => $s->sold_at?->format('d M Y'),
                'warranty_until' => $s->warranty_until?->format('d M Y'),
                'under_warranty' => $s->under_warranty,
            ]);

        return Inertia::render('Admin/Stock/Serials', [
            'serials' => $serials,
            // For the branch picker when adding serials to what is on the shelf.
            'stores' => BranchScope::storesFor($request->user()),
            'filters' => ['status' => $status, 'q' => $search],
            'statuses' => ProductSerial::STATUSES,
            'branch' => BranchScope::name($request->user()),
            'counts' => ProductSerial::query()
                ->when($branch, fn ($q) => $q->where('store_id', $branch))
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    /**
     * Every correction ever made, and what it cost.
     *
     * Adjustments were only ever visible one product at a time, so there was
     * nowhere to see that a branch had written off nine graphics cards this
     * month, or that the same person recorded all of them.
     */
    public function adjustments(Request $request, StockService $stock): Response
    {
        $branch = BranchScope::for($request->user());
        $reason = $request->query('reason');
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $query = StockMovement::where('type', StockMovement::ADJUSTMENT)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branch, fn ($q) => $q->where('store_id', $branch))
            ->when($request->integer('store'), fn ($q, $id) => $q->where('store_id', $id))
            ->when(in_array($reason, array_keys(StockService::ADJUSTMENT_REASONS), true),
                fn ($q) => $q->where('reason', $reason));

        $costs = $stock->latestUnitCosts();

        $movements = (clone $query)
            ->with(['product:id,name', 'variant:id,name', 'user:id,name', 'store:id,name'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'name' => $m->variant
                    ? "{$m->product?->name} ({$m->variant->name})"
                    : ($m->product?->name ?? 'Removed product'),
                'quantity' => (int) $m->quantity,
                'reason' => StockService::ADJUSTMENT_REASONS[$m->reason] ?? $m->reason,
                'note' => $m->note,
                'store' => $m->store?->name,
                'by' => $m->user?->name,
                'when' => $m->created_at->format('d M Y, g:i A'),
                // What those units cost, so a write-off has a number on it.
                'value' => ($costs[$m->product_id.':'.($m->product_variant_id ?: '')] ?? null) === null
                    ? null
                    : round($costs[$m->product_id.':'.($m->product_variant_id ?: '')] * $m->quantity, 2),
            ]);

        return Inertia::render('Admin/Stock/Adjustments', [
            'movements' => $movements,
            'filters' => [
                'reason' => $reason,
                'from' => $from,
                'to' => $to,
                'store' => $request->integer('store') ?: null,
            ],
            'reasons' => StockService::ADJUSTMENT_REASONS,
            'stores' => BranchScope::storesFor($request->user()),
            'branch' => BranchScope::name($request->user()),
            'summary' => $this->writeOffSummary(clone $query, $costs),
        ]);
    }

    /**
     * @param  array<string, float>  $costs
     * @return array<string, mixed>
     */
    private function writeOffSummary($query, array $costs): array
    {
        $lost = 0;
        $found = 0;
        $value = 0.0;

        (clone $query)->select(['product_id', 'product_variant_id', 'quantity'])
            ->chunk(500, function ($rows) use (&$lost, &$found, &$value, $costs) {
                foreach ($rows as $row) {
                    $row->quantity < 0 ? $lost += abs($row->quantity) : $found += $row->quantity;

                    $cost = $costs[$row->product_id.':'.($row->product_variant_id ?: '')] ?? null;

                    if ($cost !== null) {
                        $value += $cost * $row->quantity;
                    }
                }
            });

        return [
            'units_lost' => $lost,
            'units_found' => $found,
            'value_change' => round($value, 2),
        ];
    }
}
