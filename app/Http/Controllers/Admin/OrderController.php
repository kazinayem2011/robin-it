<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\Order;
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

        $query = Order::with(['user', 'items.product.images'])->latest();

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
        ]);
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
