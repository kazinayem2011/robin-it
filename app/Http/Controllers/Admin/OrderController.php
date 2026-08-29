<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DispatchOrderRequest;
use App\Http\Requests\Admin\OrderPaymentRequest;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\Coupon;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Refund;
use App\Models\User;
use App\Services\OrderEditService;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use App\Support\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            // So the refund form knows what is left before anything is typed.
            'refunds:id,order_id,amount',
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

        return Inertia::render('Admin/Orders', [
            'orders' => $query->paginate(15)->withQueryString(),
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
            $coupon
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

        // Goes through the service so cancelling an order returns its stock to the shelf.
        $orderService->updateOrderStatus($order, $validated['status']);

        if (($validated['payment_status'] ?? null) !== null) {
            $order->payment_status = $validated['payment_status'];
            $order->save();
        }

        $this->notifyCustomer($order);

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

    /**
     * Best-effort: the status change stands even if mail cannot be queued.
     */
    private function notifyCustomer(Order $order): void
    {
        try {
            $customerEmail = $order->user?->email ?? ($order->shipping_address['email'] ?? null);

            if ($customerEmail) {
                Mail::to($customerEmail)->send(new OrderStatusUpdatedMail($order));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not dispatch OrderStatusUpdatedMail: {$e->getMessage()}");
        }
    }
}
