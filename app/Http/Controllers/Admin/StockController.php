<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Services\OrderService;
use App\Services\StockService;
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
        protected StockService $stock
    ) {}

    /** Inventory overview: what is on the shelf and what has been moving. */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->with(['variants' => fn ($q) => $q->orderBy('position')->orderBy('id'), 'category'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Stock/Index', [
            'products' => $products,
            'filters' => ['search' => $search],
            'lowStockThreshold' => 10,
            'adjustmentReasons' => StockService::ADJUSTMENT_REASONS,
        ]);
    }

    /** The ledger for one product, newest first. */
    public function movements(Request $request, int $productId)
    {
        $product = Product::with('variants')->findOrFail($productId);

        $movements = StockMovement::where('product_id', $productId)
            ->when($request->query('variant_id'), fn ($q, $id) => $q->where('product_variant_id', $id))
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
            'supplier_name' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:100',
            'received_on' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
        ], [
            'lines.required' => 'Add at least one product to this delivery.',
        ]);

        $receipt = $this->stock->receive(
            [
                'supplier_name' => $validated['supplier_name'] ?? null,
                'invoice_number' => $validated['invoice_number'] ?? null,
                'received_on' => $validated['received_on'] ?? now()->toDateString(),
                'note' => $validated['note'] ?? null,
            ],
            $validated['lines'],
            $request->user()?->id
        );

        return $this->successResponse(
            $receipt,
            "Received {$receipt->total_quantity} unit(s) into stock as {$receipt->reference}.",
            201
        );
    }

    /** Past deliveries, for reference and reconciliation. */
    public function receipts(Request $request)
    {
        $receipts = StockReceipt::with(['items.product:id,name', 'items.variant:id,name', 'user:id,name'])
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
        ], [
            'quantity.not_in' => 'Enter how many units to add or remove.',
            'reason.in' => 'Choose a reason for this adjustment.',
        ]);

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
            $request->user()?->id
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

    /** Options that can hold stock, for the receive and adjust pickers. */
    public function units(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->select('id', 'name', 'stock_quantity', 'has_variants')
            ->with(['variants:id,product_id,name,stock_quantity,is_active'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(50)
            ->get();

        return $this->successResponse($products);
    }
}
