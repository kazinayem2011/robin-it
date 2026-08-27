<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourierRequest;
use App\Models\Courier;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The delivery companies the shop hands parcels to.
 *
 * Seeded with the carriers most Bangladeshi shops use, and editable, because
 * carriers change their tracking URLs and correcting one should not need a
 * deploy.
 */
class CourierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Couriers', [
            'couriers' => Courier::query()
                ->withCount('orders')
                ->ordered()
                ->get(),
            'placeholder' => Courier::PLACEHOLDER,
        ]);
    }

    public function store(CourierRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $courier = Courier::create($validated + [
            'slug' => Courier::uniqueSlug($validated['name']),
            'sort_order' => $validated['sort_order'] ?? ((int) Courier::max('sort_order') + 1),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($courier, "'{$courier->name}' added.", 201);
    }

    public function update(CourierRequest $request, int $id): JsonResponse
    {
        $courier = Courier::findOrFail($id);

        $courier->update($request->validated());

        return $this->successResponse($courier, 'Courier updated.');
    }

    /**
     * Retire a courier.
     *
     * Hidden rather than deleted once parcels have gone out with it, so an old
     * order can still say who carried it.
     */
    public function destroy(int $id): JsonResponse
    {
        $courier = Courier::withCount('orders')->findOrFail($id);

        if ($courier->orders_count > 0) {
            $courier->update(['is_active' => false]);

            return $this->successResponse(
                $courier,
                "'{$courier->name}' has carried {$courier->orders_count} order(s), "
                    .'so it has been hidden rather than deleted.'
            );
        }

        $courier->delete();

        return $this->successResponse([], 'Courier removed.');
    }
}
