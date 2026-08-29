<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use App\Support\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the shop has asked its suppliers for.
 *
 * Receipts recorded what arrived and nothing recorded what was asked for, so a
 * short shipment was invisible and "when are those back in" had no answer.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $branch = BranchScope::for($request->user());

        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'store:id,name', 'items'])
            ->when($branch, fn ($q) => $q->where('store_id', $branch))
            ->when(
                array_key_exists((string) $status, PurchaseOrder::STATUSES),
                fn ($q) => $q->where('status', $status)
            )
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Purchasing', [
            'orders' => $orders,
            'filters' => ['status' => $status],
            'statuses' => PurchaseOrder::STATUSES,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'stores' => BranchScope::storesFor($request->user()),
            'branch' => BranchScope::name($request->user()),
            'counts' => PurchaseOrder::query()
                ->when($branch, fn ($q) => $q->where('store_id', $branch))
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $order = $this->orders->save(
            null,
            Supplier::findOrFail($data['supplier_id']),
            $request->user(),
            $data['lines'],
            $data
        );

        return $this->successResponse($order, "{$order->reference} saved as a draft.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);
        $data = $this->validated($request);

        $order = $this->orders->save(
            $order,
            Supplier::findOrFail($data['supplier_id']),
            $request->user(),
            $data['lines'],
            $data
        );

        return $this->successResponse($order, "{$order->reference} updated.");
    }

    public function send(int $id): JsonResponse
    {
        $order = $this->orders->send(PurchaseOrder::findOrFail($id));

        return $this->successResponse($order, "{$order->reference} is now with {$order->supplier_name}.");
    }

    public function cancel(int $id): JsonResponse
    {
        $order = $this->orders->cancel(PurchaseOrder::findOrFail($id));

        return $this->successResponse($order, "{$order->reference} cancelled.");
    }

    /** Book in what actually turned up. */
    public function receive(Request $request, int $id): JsonResponse
    {
        $order = PurchaseOrder::findOrFail($id);

        $data = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id',
            'invoice_number' => 'nullable|string|max:80',
            'received_on' => 'nullable|date',
            'note' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_item_id' => 'required|integer',
            'lines.*.quantity' => 'required|integer|min:0|max:100000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $receipt = $this->orders->receive($order, $request->user(), $data['lines'], $data);
        $order->refresh();

        return $this->successResponse(
            ['receipt' => $receipt, 'order' => $order->fresh('items')],
            $order->outstanding > 0
                ? "Received. {$order->outstanding} still outstanding on {$order->reference}."
                : "{$order->reference} is complete."
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'store_id' => 'nullable|integer|exists:stores,id',
            'expected_on' => 'nullable|date',
            'note' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
        ]);
    }
}
