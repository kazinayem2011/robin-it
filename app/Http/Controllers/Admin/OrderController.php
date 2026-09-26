<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DispatchOrderRequest;
use App\Http\Requests\Admin\OrderPaymentRequest;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Models\Coupon;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ProductStock;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderEditService;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use App\Services\StockService;
use App\Support\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * Orders management view.
     */
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'all');
        $search = $request->input('search', '');

        $query = Order::with([
            'user', 'items.product.images',
            'courier:id,name,tracking_url_template',
            /*
             * So the refund form knows what is left before anything is typed,
             * and so the order can show the money as a story rather than a
             * balance: when each refund went out and what it was for. The
             * amount alone was enough for the form and left the log reading
             * "Invalid Date · Refund".
             */
            'refunds:id,order_id,amount,reason,created_at',
            // And the payment form what is still owed.
            'payments',
        ])->latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($search)) {
            $term = SearchTerm::contains($search);

            $query->where(function ($q) use ($term) {
                $q->where('order_number', 'LIKE', $term)
                    ->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('name', 'LIKE', $term)
                            ->orWhere('phone', 'LIKE', $term)
                            ->orWhere('email', 'LIKE', $term);
                    });
            });
        }

        $orders = $query->paginate(15)->withQueryString();
        $this->attachShipFrom($orders->getCollection());

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
            // The branches an order can ship from, for the counter-sale form
            // and the return desk.
            'branches' => Store::holdsStock()
                ->orderByDesc('fulfils_online')->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'fulfils_online']),
            'currentStatus' => $status,
            'search' => $search,
            'couriers' => Courier::active()->ordered()->get(['id', 'name', 'phone']),
            'refundMethods' => collect(Refund::METHODS)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'refundReasons' => collect(Refund::REASONS)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'paymentMethods' => collect(OrderPayment::METHODS)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
        ]);
    }

    /**
     * Record money received against an order.
     *
     * Not a gateway: this writes down what somebody handed over at the
     * counter, or sent by bKash before a delivery went out.
     */
    public function recordPayment(
        OrderPaymentRequest $request,
        OrderPaymentService $payments,
        int $id
    ): JsonResponse {
        $order = Order::findOrFail($id);
        $validated = $request->validated();

        $payment = $payments->record(
            $order,
            $request->user(),
            (float) $validated['amount'],
            $validated['method'],
            $validated['reference'] ?? null,
            $validated['note'] ?? null,
            $validated['received_on'] ?? null,
        );

        $order->refresh();

        $message = $order->amount_due > 0
            ? 'Recorded. '.number_format($order->amount_due, 2).' still owed on this order.'
            : 'Recorded. This order is paid in full.';

        return $this->successResponse([
            'payment' => $payment->only(['id', 'amount', 'method', 'reference', 'received_on']),
            'amount_paid' => $order->amount_paid,
            'amount_due' => $order->amount_due,
            'payment_state' => $order->payment_state,
        ], $message, 201);
    }

    /**
     * Move an order to a new status.
     */
    /**
     * Take an order at the counter or over the phone.
     *
     * For a registered customer or a walk-in with nothing but a name and a
     * number. Either way it goes through the same OrderService the storefront
     * uses, so the stock check, the reservation, the coupon and the
     * confirmation are the ones already known to work rather than a second set
     * written for the counter.
     */
    public function store(Request $request, OrderService $orders): JsonResponse
    {
        PhoneHelper::canonicalise($request, 'phone');

        $data = $request->validate([
            // A customer if they have an account, or nothing at all if they
            // walked in — the phone number is what identifies a guest order.
            'user_id' => 'nullable|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', PhoneHelper::RULE],
            'street_address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'zone' => 'nullable|string|max:100',
            'payment_method' => 'nullable|string|in:'.implode(',', Order::PAYMENT_METHODS),
            'coupon_code' => 'nullable|string|max:50',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity' => 'required|integer|min:1|max:1000',
            // The branch the counter is at; it ships from there first.
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('holds_stock', true)->where('is_active', true)],
        ], [
            'phone.regex' => PhoneHelper::MESSAGE,
            'street_address.required' => 'Enter where this is going, or the shop counter if they are taking it with them.',
        ]);

        $customer = ! empty($data['user_id'])
            ? User::where('role', User::ROLE_CUSTOMER)->find($data['user_id'])
            : null;

        $coupon = ! empty($data['coupon_code'])
            ? Coupon::findByCode($data['coupon_code'])
            : null;

        if (! empty($data['coupon_code']) && ! $coupon) {
            return $this->errorResponse(
                "There is no code \"{$data['coupon_code']}\".",
                422,
                ApiCode::COUPON_INVALID
            );
        }

        $order = $orders->placeForCustomer(
            $data['lines'],
            $data + ['payment_method' => $data['payment_method'] ?? 'COD'],
            $customer,
            $coupon,
            isset($data['store_id']) ? (int) $data['store_id'] : null,
        );

        return $this->successResponse(
            $order->load('items'),
            "{$order->order_number} created for {$data['name']}. "
                .'Record the payment when the money is taken.'
        );
    }

    /**
     * Customers to attach an order to, matched on the things a person says
     * over the phone: their name, their number, or their email.
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('search', ''));

        if (mb_strlen($term) < 2) {
            return $this->successResponse([]);
        }

        $like = '%'.$term.'%';

        return $this->successResponse(
            User::query()
                ->where('role', User::ROLE_CUSTOMER)
                // A suspended customer cannot order for themselves, so staff
                // must not be able to order on their behalf either.
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like))
                ->orderBy('name')
                ->limit(15)
                ->get(['id', 'name', 'email', 'phone'])
        );
    }

    /**
     * Change what is on an order after it was placed.
     *
     * A customer ringing to add a stick of RAM meant cancelling and starting
     * again, which lost the order number, the tracking link already texted to
     * them, and any deposit's connection to the order it was paid against.
     */
    public function updateLines(Request $request, OrderEditService $edits, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);

        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1',
            'lines.*.order_item_id' => 'nullable|integer',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity' => 'required|integer|min:0|max:10000',
        ]);

        $order = $edits->apply($order, $request->user(), $data['lines'], $data['reason'] ?? null);

        return $this->successResponse(
            $order,
            "{$order->order_number} updated. New total ".number_format((float) $order->total, 2).'.'
        );
    }

    public function updateStatus(OrderStatusRequest $request, OrderService $orderService, int $id): JsonResponse
    {
        $validated = $request->validated();
        $order = Order::findOrFail($id);
        $statusBefore = $order->status;

        // Goes through the service so cancelling an order returns its stock to the shelf.
        $orderService->updateOrderStatus($order, $validated['status']);

        if (($validated['payment_status'] ?? null) !== null) {
            $order->payment_status = $validated['payment_status'];
            $order->save();
        }

        /*
         * Email and text, when the status actually moved.
         *
         * This sent the email alone, so a cancellation — on by default in the
         * SMS settings because no courier ever tells a customer about one —
         * never sent its text. And it sent the email whatever happened, so
         * saving a payment status told the customer their order was "being
         * packed" all over again.
         */
        if ($order->status !== $statusBefore) {
            $orderService->notifyStatusChange($order);
        }

        return $this->successResponse(
            $order,
            "Order #{$order->order_number} status updated to ".ucfirst($order->status).'.'
        );
    }

    /**
     * Hand a parcel to a carrier.
     *
     * Its own action rather than a status change, because shipping an order
     * without recording who took it is what left customers ringing up with a
     * question nobody could answer.
     */
    /**
     * The branches this order could ship from, and whether each has it all.
     *
     * Each branch's holding is counted as it would stand after the order's
     * own units came back to it, since that is what moving there does.
     */
    public function shipFromOptions(StockService $stock, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $holding = $stock->branchesHolding($order);

        $branches = Store::holdsStock()
            ->orderByDesc('fulfils_online')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'fulfils_online']);

        $levels = ProductStock::query()
            ->whereIn('store_id', $branches->pluck('id'))
            ->whereIn('product_id', $order->items->pluck('product_id'))
            ->get(['product_id', 'product_variant_id', 'store_id', 'quantity']);

        $options = $branches->map(function (Store $branch) use ($order, $holding, $levels) {
            $short = [];

            foreach ($order->items as $item) {
                $key = $item->product_id.':'.($item->product_variant_id ?: '-');
                $needed = array_sum($holding[$key] ?? []);

                if ($needed === 0) {
                    continue;
                }

                $has = (int) $levels
                    ->where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->where('store_id', $branch->id)
                    ->sum('quantity');

                $heldHere = $holding[$key][$branch->id] ?? 0;

                // Already all here: covered, even on a pre-order, where the
                // branch's own count is below zero until the delivery lands.
                if ($heldHere >= $needed) {
                    continue;
                }

                // The units the order already holds here count as its own.
                $available = $has + $heldHere;

                if ($available < $needed) {
                    $short[] = ['name' => $item->display_name, 'needed' => $needed, 'available' => max(0, $available)];
                }
            }

            $units = 0;
            foreach ($holding as $stores) {
                $units += $stores[$branch->id] ?? 0;
            }

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_default' => (bool) $branch->fulfils_online,
                // Everything the order holds is here already.
                'is_current' => $units > 0 && $units === array_sum(array_map('array_sum', $holding)),
                'covers' => $short === [],
                'short' => $short,
            ];
        });

        /*
         * Each line on its own, for choosing branch by item: where its units
         * are now, and what each branch could give it — its own stock plus
         * whatever this line already holds there.
         */
        $lines = $order->items->map(function ($item) use ($holding, $branches, $levels) {
            $key = $item->product_id.':'.($item->product_variant_id ?: '-');
            $current = $holding[$key] ?? [];

            if (array_sum($current) === 0) {
                return null;
            }

            return [
                'order_item_id' => $item->id,
                'name' => $item->display_name,
                'units' => array_sum($current),
                // Ships later: a pre-order, or more than was in stock.
                'owed' => $item->was_preordered,
                'waiting_for_stock' => $item->waiting_for_stock,
                'current' => collect($current)->map(fn ($u, $id) => ['id' => (int) $id, 'units' => $u])->values(),
                'branches' => $branches->map(fn (Store $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    // Units really there for this line: the branch's count
                    // with the line's own units back — so an owed unit is
                    // not counted as one it could give.
                    'available' => max(0, (int) $levels
                        ->where('product_id', $item->product_id)
                        ->where('product_variant_id', $item->product_variant_id)
                        ->where('store_id', $b->id)
                        ->sum('quantity') + max(0, $current[$b->id] ?? 0)),
                ])->values(),
            ];
        })->filter()->values();

        return $this->successResponse([
            'current' => $this->shipFromSummary($holding),
            'can_change' => $this->canChangeShipFrom($order),
            'branches' => $options->values(),
            'lines' => $lines,
        ]);
    }

    /**
     * Where the order's units come from: the whole order from one branch
     * (`store_id`), or item by item (`lines`, each a branch => units split).
     */
    public function shipFrom(Request $request, StockService $stock, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $branch = ['integer', Rule::exists('stores', 'id')->where('holds_stock', true)->where('is_active', true)];

        $data = $request->validate([
            'store_id' => ['required_without:lines', ...$branch],
            'lines' => 'required_without:store_id|array|min:1',
            'lines.*.order_item_id' => 'required|integer',
            'lines.*.stores' => 'required|array|min:1',
            'lines.*.stores.*' => 'integer|min:0',
        ]);

        if (! $this->canChangeShipFrom($order)) {
            return $this->errorResponse(
                'The branch can only be changed before the order is dispatched.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if (isset($data['store_id'])) {
            $store = Store::find($data['store_id']);
            $moved = $stock->moveOrderTo($order, $store->id);
            $message = $moved > 0
                ? "{$order->order_number} now ships from {$store->name}."
                : "{$order->order_number} already ships from {$store->name}.";
        } else {
            DB::transaction(function () use ($data, $order, $stock) {
                foreach ($data['lines'] as $line) {
                    $item = $order->items->firstWhere('id', (int) $line['order_item_id']);

                    if (! $item) {
                        throw new StorefrontException('One of those items is not on this order.', 422, ApiCode::VALIDATION_ERROR);
                    }

                    [$product, $variant] = $stock->resolveUnit($item->product_id, $item->product_variant_id);
                    $stock->allocateOrderLine($order, $product, $variant, $line['stores']);
                }
            });

            $message = "Updated where {$order->order_number} ships from.";
        }

        return $this->successResponse(
            ['ship_from' => $this->shipFromSummary($stock->branchesHolding($order))],
            $message
        );
    }

    /**
     * Before the parcel leaves, and while the units are the order's: once it
     * is cancelled they are back on the shelf, and once dispatched they have
     * gone from whichever branch packed it.
     */
    private function canChangeShipFrom(Order $order): bool
    {
        return in_array($order->status, ['pending', 'processing'], true)
            && $order->stock_released_at === null;
    }

    /**
     * Where each order on a page ships from, in one query for the page.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function attachShipFrom($orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $rows = StockMovement::query()
            ->where('reference_type', (new Order)->getMorphClass())
            ->whereIn('reference_id', $orders->pluck('id'))
            ->where('type', '!=', StockMovement::WRITE_OFF)
            ->whereNotNull('store_id')
            ->groupBy('reference_id', 'store_id')
            ->selectRaw('reference_id, store_id, SUM(quantity) as net')
            ->get();

        $names = Store::whereIn('id', $rows->pluck('store_id')->unique())->pluck('name', 'id');

        foreach ($orders as $order) {
            $order->setAttribute('ship_from', $rows->where('reference_id', $order->id)
                ->map(fn ($r) => ['id' => (int) $r->store_id, 'name' => $names[$r->store_id] ?? 'Branch', 'units' => -(int) $r->net])
                ->filter(fn ($b) => $b['units'] > 0)
                ->sortByDesc('units')
                ->values()
                ->all());
            $order->setAttribute('can_change_ship_from', $this->canChangeShipFrom($order));
        }
    }

    /**
     * @param  array<string, array<int, int>>  $holding
     * @return list<array{id: int, name: string, units: int}>
     */
    private function shipFromSummary(array $holding): array
    {
        $totals = [];

        foreach ($holding as $stores) {
            foreach ($stores as $storeId => $units) {
                $totals[$storeId] = ($totals[$storeId] ?? 0) + $units;
            }
        }

        arsort($totals);
        $names = Store::whereIn('id', array_keys($totals))->pluck('name', 'id');

        return collect($totals)
            ->map(fn ($units, $id) => ['id' => (int) $id, 'name' => $names[$id] ?? 'Branch', 'units' => $units])
            ->values()
            ->all();
    }

    public function dispatchOrder(DispatchOrderRequest $request, OrderService $orders, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $validated = $request->validated();

        $orders->dispatchOrder(
            $order,
            Courier::findOrFail($validated['courier_id']),
            $validated['tracking_number'] ?? null
        );

        return $this->successResponse(
            $order->load('courier:id,name,tracking_url_template'),
            "Order #{$order->order_number} is on its way with {$order->courier->name}."
        );
    }
}
