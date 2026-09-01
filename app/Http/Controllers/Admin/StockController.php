<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Services\OrderService;
use App\Services\SerialService;
use App\Services\StockService;
use App\Support\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything that moves stock, in one place.
 *
 * The product form no longer carries a stock field at all: an admin who could
 * type an absolute quantity could — and did — resurrect already-sold units by
 * saving a form they had opened before the sale. Units now enter through a
 * receipt, leave through an order, and are corrected only by an audited
 * adjustment that has to state a reason.
 */
class StockController extends Controller
{
    public function __construct(
        protected StockService $stock,
        protected SerialService $serials
    ) {}

    /** Inventory overview: what is on the shelf and what has been moving. */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $onlyReorder = $request->boolean('reorder');
        /*
         * A storekeeper assigned to a branch sees that branch, whatever the
         * query string asks for. Narrowed rather than refused: a branch is a
         * filter here, and being shown your own shelves is the right answer to
         * asking for somebody else's.
         */
        $storeId = BranchScope::narrow($request->user(), $request->integer('store') ?: null);

        $products = Product::query()
            ->with([
                'variants' => fn ($q) => $q->orderBy('position')->orderBy('id'),
                'category',
                'stockLevels.store:id,name',
            ])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($onlyReorder, fn ($q) => $q->needingReorder())
            // Narrowing to a branch shows what that branch is actually holding,
            // which is the question someone standing in it wants answered.
            ->when($storeId, fn ($q) => $q->whereHas(
                'stockLevels',
                fn ($inner) => $inner->where('store_id', $storeId)->where('quantity', '>', 0)
            ))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Stock/Index', [
            'products' => $products,
            'filters' => ['search' => $search, 'reorder' => $onlyReorder, 'store' => $storeId],
            'stores' => BranchScope::storesFor($request->user()),
            // So the screen can say which branch it is confined to rather than
            // silently showing a third of the shop.
            'branch' => BranchScope::name($request->user()),
            'defaultReorderLevel' => (int) config('inventory.default_reorder_level', 10),
            'adjustmentReasons' => StockService::ADJUSTMENT_REASONS,
            'summary' => $this->summary($storeId),
            // The delivery form fetches the supplier list from its own
            // endpoint; suppliers are managed in their own section.
            /*
             * Kind travels too: one of these is not a supplier but the standing
             * "Opening balance" source, and the delivery form says so rather
             * than listing it among the companies the shop buys from.
             */
            'suppliers' => Supplier::active()
                ->orderByRaw("CASE WHEN kind = 'opening' THEN 1 ELSE 0 END")
                ->orderBy('name')
                ->get(['id', 'name', 'kind']),
        ]);
    }

    /**
     * What the shelf is worth and what needs buying.
     *
     * Valuation uses the most recent purchase price for each unit rather than
     * its retail price: this is what the stock cost, not what it will sell for.
     * Anything never received through a delivery has no cost and is left out
     * rather than guessed at, and how many those are is reported alongside so
     * the figure is never mistaken for the whole picture.
     *
     * @return array{
     *     units:int, needs_reorder:int, valuation:float, uncosted_units:int
     * }
     */
    /**
     * Refuse an instruction aimed at a branch this person does not work in.
     *
     * Refused rather than quietly redirected, because these are instructions
     * rather than filters: silently moving somebody's delivery or write-off to
     * a different branch than the one they named would be worse than telling
     * them no.
     */
    private function refuseOtherBranch(Request $request, ?int $storeId): ?JsonResponse
    {
        /*
         * Naming no branch is not naming someone else's. A delivery or a
         * write-off submitted without one lands wherever the person doing it
         * works, which BranchScope::narrow decides; only an explicit branch
         * that is not theirs is refused.
         */
        if ($storeId === null || BranchScope::allows($request->user(), $storeId)) {
            return null;
        }

        $branch = BranchScope::name($request->user());

        return $this->errorResponse(
            "You can only do this at {$branch}.",
            403,
            ApiCode::FORBIDDEN
        );
    }

    /**
     * @param  int|null  $storeId  the branch to value, or null for the whole shop
     */
    private function summary(?int $storeId = null): array
    {
        // The same lookup the order lines use when they snapshot their cost.
        $costs = $this->stock->latestUnitCosts();

        $valuation = 0.0;
        $units = 0;
        $uncosted = 0;

        Product::query()
            ->where('is_active', true)
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true),
                'stockLevels',
            ])
            ->chunk(200, function ($products) use (&$valuation, &$units, &$uncosted, $costs, $storeId) {
                foreach ($products as $product) {
                    /*
                     * Held at this branch, not across the shop. A storekeeper
                     * shown the whole company's stock valuation has been told
                     * something that is not about the shelves in front of them.
                     */
                    $holdings = $storeId
                        ? $product->stockLevels
                            ->where('store_id', $storeId)
                            ->map(fn ($level) => [
                                (int) $level->quantity,
                                $product->id.':'.($level->product_variant_id ?: ''),
                            ])
                        : ($product->has_variants
                            ? $product->variants->map(fn ($v) => [$v->stock_quantity, $product->id.':'.$v->id])
                            : collect([[$product->stock_quantity, $product->id.':']]));

                    foreach ($holdings as [$quantity, $key]) {
                        $units += $quantity;

                        if (isset($costs[$key])) {
                            $valuation += $costs[$key] * $quantity;
                        } else {
                            $uncosted += $quantity;
                        }
                    }
                }
            });

        return [
            'units' => $units,
            'needs_reorder' => Product::needingReorder()->count(),
            'branch_scoped' => $storeId !== null,
            'valuation' => round($valuation, 2),
            'uncosted_units' => $uncosted,
        ];
    }

    /** The ledger for one product, newest first. */
    public function movements(Request $request, int $productId)
    {
        $product = Product::with('variants')->findOrFail($productId);

        $branch = BranchScope::for($request->user());

        $movements = StockMovement::where('product_id', $productId)
            ->when($request->query('variant_id'), fn ($q, $id) => $q->where('product_variant_id', $id))
            // Somebody else's branch receiving a pallet is not this branch's
            // history, and the running balance shown beside it is not theirs.
            ->when($branch, fn ($q) => $q->where('store_id', $branch))
            ->with(['user:id,name', 'variant:id,name'])
            ->latest('id')
            ->paginate(50);

        return $this->successResponse([
            'product' => $product->only(['id', 'name', 'stock_quantity', 'has_variants']),
            'movements' => $movements,
            // Surfaces a drift between the ledger and the cached balance rather
            // than leaving it to be discovered by a customer.
            'integrity' => $this->stock->verify($product),
        ]);
    }

    /** Receive a delivery. The only way stock enters the shelf. */
    public function receive(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_name' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:100',
            'received_on' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            // Optional, and one per line. Most of a computer shop's stock —
            // cables, paste, a bag of screws — has no serial worth keeping,
            // and demanding one would make receiving a chore nobody finishes.
            'lines.*.serials' => 'nullable|string|max:20000',
            'store_id' => 'nullable|exists:stores,id',
        ], [
            'lines.required' => 'Add at least one product to this delivery.',
        ]);

        if ($refusal = $this->refuseOtherBranch($request, $validated['store_id'] ?? null)) {
            return $refusal;
        }

        $receipt = $this->stock->receive(
            [
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'invoice_number' => $validated['invoice_number'] ?? null,
                'received_on' => $validated['received_on'] ?? now()->toDateString(),
                'note' => $validated['note'] ?? null,
                'store_id' => BranchScope::narrow($request->user(), $validated['store_id'] ?? null),
            ],
            $validated['lines'],
            $request->user()?->id
        );

        $serials = $this->captureSerials($validated, $receipt, $request);

        $message = "Received {$receipt->total_quantity} unit(s) into stock as {$receipt->reference}.";

        if ($serials['added'] > 0) {
            $message .= " {$serials['added']} serial number(s) recorded.";
        }

        // Said out loud rather than swallowed: a serial the shop already holds
        // is a typo or a supplier duplicate, and either way somebody needs to
        // go and look at the box.
        if ($serials['skipped'] !== []) {
            $message .= ' Already on the books, so not added again: '
                .implode(', ', array_slice($serials['skipped'], 0, 5))
                .(count($serials['skipped']) > 5 ? '…' : '').'.';
        }

        return $this->successResponse($receipt, $message, 201);
    }

    /**
     * Take in whatever serials were typed against the delivery's lines.
     *
     * @return array{added: int, skipped: array<int, string>}
     */
    private function captureSerials(array $validated, $receipt, Request $request): array
    {
        $added = 0;
        $skipped = [];
        $storeId = BranchScope::narrow($request->user(), $validated['store_id'] ?? null);

        foreach ($validated['lines'] as $line) {
            if (blank($line['serials'] ?? null)) {
                continue;
            }

            [$product, $variant] = $this->stock->resolveUnit(
                (int) $line['product_id'],
                $line['product_variant_id'] ?? null
            );

            // One per line, however the person pasted them in.
            $result = $this->serials->receive(
                $product,
                $variant?->id,
                preg_split('/[\r\n,]+/', $line['serials']) ?: [],
                $storeId,
                $receipt
            );

            $added += $result['added'];
            $skipped = array_merge($skipped, $result['skipped']);
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Past deliveries, for reference and reconciliation. */
    public function receipts(Request $request)
    {
        $branch = BranchScope::for($request->user());

        $receipts = StockReceipt::with(['items.product:id,name', 'items.variant:id,name', 'user:id,name', 'supplier:id,name'])
            ->when($branch, fn ($q) => $q->where('store_id', $branch))
            ->latest('received_on')
            ->latest('id')
            ->paginate(25);

        return $this->successResponse($receipts);
    }

    /**
     * A counted correction — breakage, theft, a stock-take that disagrees.
     *
     * Takes a change and a reason, never an absolute total, so the ledger keeps
     * explaining how the balance got where it is.
     */
    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|not_in:0',
            'reason' => 'required|string|in:'.implode(',', array_keys(StockService::ADJUSTMENT_REASONS)),
            'note' => 'nullable|string|max:1000',
            'store_id' => 'nullable|exists:stores,id',
        ], [
            'quantity.not_in' => 'Enter how many units to add or remove.',
            'reason.in' => 'Choose a reason for this adjustment.',
        ]);

        if ($refusal = $this->refuseOtherBranch($request, $validated['store_id'] ?? null)) {
            return $refusal;
        }

        [$product, $variant] = $this->stock->resolveUnit(
            (int) $validated['product_id'],
            $validated['product_variant_id'] ?? null
        );

        $movement = $this->stock->adjust(
            $product,
            $variant,
            (int) $validated['quantity'],
            $validated['reason'],
            $validated['note'] ?? null,
            $request->user()?->id,
            BranchScope::narrow($request->user(), $validated['store_id'] ?? null)
        );

        $name = $variant ? "{$product->name} ({$variant->name})" : $product->name;

        return $this->successResponse(
            $movement,
            "Stock for {$name} adjusted to {$movement->balance_after}."
        );
    }

    /**
     * Take back a delivered order, item by item.
     *
     * Resellable units go to the shelf; damaged ones are written off so a broken
     * part can never be sold on to the next customer.
     */
    public function returnOrder(Request $request, OrderService $orders, int $orderId)
    {
        $order = Order::with('items')->findOrFail($orderId);

        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.order_item_id' => 'required|integer',
            'lines.*.resellable' => 'nullable|integer|min:0',
            'lines.*.damaged' => 'nullable|integer|min:0',
        ]);

        $order = $orders->returnOrder($order, $validated['lines'], $validated['note'] ?? null);

        return $this->successResponse($order, "Order {$order->order_number} has been returned.");
    }

    /**
     * Move units between branches.
     *
     * Written as a pair of movements that net to zero, so the shop's holding is
     * unchanged and the ledger records where the units went.
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1|max:100000',
            'from_store_id' => 'required|exists:stores,id',
            'to_store_id' => 'required|exists:stores,id|different:from_store_id',
            'note' => 'nullable|string|max:1000',
        ], [
            'to_store_id.different' => 'Choose two different branches.',
        ]);

        // Sending stock away is the one move that empties a shelf somebody
        // else is answerable for, so it may only start from your own.
        if ($refusal = $this->refuseOtherBranch($request, (int) $validated['from_store_id'])) {
            return $refusal;
        }

        [$product, $variant] = $this->stock->resolveUnit(
            (int) $validated['product_id'],
            $validated['product_variant_id'] ?? null
        );

        $this->stock->transfer(
            $product,
            $variant,
            (int) $validated['quantity'],
            (int) $validated['from_store_id'],
            (int) $validated['to_store_id'],
            $validated['note'] ?? null,
            $request->user()?->id
        );

        $name = $variant ? "{$product->name} ({$variant->name})" : $product->name;

        return $this->successResponse(
            ['breakdown' => $this->stock->branchBreakdown($product->fresh(), $variant?->fresh())],
            "Moved {$validated['quantity']} x {$name} between branches."
        );
    }

    /** What each branch is holding of one thing. */
    public function branches(Request $request, int $productId)
    {
        $validated = $request->validate([
            'variant_id' => 'nullable|integer',
        ]);

        [$product, $variant] = $this->stock->resolveUnit(
            $productId,
            $validated['variant_id'] ?? null
        );

        $breakdown = $this->stock->branchBreakdown($product, $variant);
        $branch = BranchScope::for($request->user());

        // What the other branches are holding is not this person's to see.
        if ($branch) {
            $breakdown = collect($breakdown)
                ->filter(fn ($row) => (int) ($row['store_id'] ?? 0) === $branch)
                ->values()
                ->all();
        }

        return $this->successResponse($breakdown);
    }

    /** Options that can hold stock, for the receive and adjust pickers. */
    public function units(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            /*
             * Price included, because three screens use this list to build
             * something priced — a purchase order, a counter sale, an edited
             * order — and without it each showed a running total of zero while
             * the server quietly worked out the real one.
             */
            ->select('id', 'name', 'stock_quantity', 'has_variants', 'price', 'discount_price')
            ->with(['variants:id,product_id,name,stock_quantity,is_active,price,discount_price'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(50)
            ->get();

        return $this->successResponse($products);
    }
}
