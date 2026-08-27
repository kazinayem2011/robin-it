<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundRequest;
use App\Models\Order;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money given back.
 */
class RefundController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): Response
    {
        $filters = [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'reason' => (string) $request->query('reason', 'all'),
        ];

        $query = Refund::query()
            ->with(['order:id,order_number,total', 'processedBy:id,name'])
            ->between($filters['from'], $filters['to'])
            ->when(
                $filters['reason'] !== 'all' && $filters['reason'] !== '',
                fn ($q) => $q->where('reason', $filters['reason'])
            )
            ->orderByDesc('refunded_on')
            ->orderByDesc('id');

        return Inertia::render('Admin/Refunds', [
            'refunds' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => $filters,
            'methods' => collect(Refund::METHODS)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'reasons' => collect(Refund::REASONS)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            // Money that actually left, so a cash-never-collected entry does
            // not read as a payout.
            'total' => round((float) (clone $query)->settled()->sum('amount'), 2),
        ]);
    }

    public function store(RefundRequest $request, int $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);

        $refund = $this->refunds->refund($order, $request->validated(), $request->user()?->id);

        return $this->successResponse(
            $refund->load('order:id,order_number,total'),
            '৳'.number_format($refund->amount, 2)." refunded on #{$order->order_number}.",
            201
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $refund = Refund::findOrFail($id);

        $this->refunds->remove($refund);

        return $this->successResponse([], 'Refund removed.');
    }
}
