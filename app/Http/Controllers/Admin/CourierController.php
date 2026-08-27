<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourierRequest;
use App\Models\Courier;
use App\Services\Courier\CourierDriverRegistry;
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
    public function __construct(private readonly CourierDriverRegistry $drivers) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Couriers', [
            // Courier::toArray strips the credentials and leaves a flag, so
            // live keys never travel to the browser.
            'couriers' => Courier::query()
                ->withCount('orders')
                ->ordered()
                ->get(),
            'placeholder' => Courier::PLACEHOLDER,
            // The credential form is built from what each driver says it
            // needs, so adding a carrier does not mean writing a form too.
            'drivers' => $this->drivers->all(),
        ]);
    }

    public function store(CourierRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $courier = Courier::create($this->withCredentials($validated) + [
            'slug' => Courier::uniqueSlug($validated['name']),
            'sort_order' => $validated['sort_order'] ?? ((int) Courier::max('sort_order') + 1),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($courier, "'{$courier->name}' added.", 201);
    }

    public function update(CourierRequest $request, int $id): JsonResponse
    {
        $courier = Courier::findOrFail($id);

        $courier->update($this->withCredentials($request->validated(), $courier));

        return $this->successResponse($courier, 'Courier updated.');
    }

    /**
     * Fold the submitted credential fields into one encrypted column.
     *
     * A blank secret means "leave it as it is" rather than "clear it": the
     * form never receives the saved value back, so an empty box is the normal
     * state of a field that is already set.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withCredentials(array $validated, ?Courier $courier = null): array
    {
        $submitted = $validated['credentials'] ?? null;
        unset($validated['credentials']);

        if (! is_array($submitted)) {
            return $validated;
        }

        $existing = $courier?->credentials ?? [];
        $merged = $existing;

        foreach ($submitted as $key => $value) {
            if (blank($value)) {
                continue;
            }

            $merged[(string) $key] = (string) $value;
        }

        $validated['credentials'] = $merged ?: null;

        return $validated;
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
