<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DispatchOrderRequest;
use App\Http\Requests\Admin\OrderPaymentRequest;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Refund;
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
