<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourierRequest;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Services\Courier\CourierDriverRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            /*
             * The address-to-area mappings, grouped by courier.
             *
             * Without these every parcel books against the one default zone
             * saved with the credentials, which is right for the shop's own
             * district and wrong for the other sixty-three.
             */
            'zones' => CourierZone::orderBy('city')->orderBy('zone')->get()
                ->groupBy('courier_id'),
        ]);
    }

    /** Map one of the shop's delivery areas to a courier's own ids. */
    public function storeZone(Request $request, int $id): JsonResponse
    {
        $courier = Courier::findOrFail($id);

        $data = $request->validate([
            'city' => 'required|string|max:100',
            'zone' => 'nullable|string|max:100',
            'city_id' => 'nullable|string|max:32',
            'zone_id' => 'nullable|string|max:32',
            'area_id' => 'nullable|string|max:32',
        ]);

        if (blank($data['city_id'] ?? null) && blank($data['zone_id'] ?? null) && blank($data['area_id'] ?? null)) {
            return $this->errorResponse(
                'Enter at least one id from the courier’s own area list, or the mapping does nothing.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        /*
         * Saved over any existing row for the same place rather than refused.
         * Somebody correcting a mapping types the same city and zone again;
         * telling them it already exists makes them hunt for a row to edit.
         */
        $zone = CourierZone::updateOrCreate(
            [
                'courier_id' => $courier->id,
                'city' => CourierZone::normalise($data['city']),
                'zone' => CourierZone::normalise($data['zone'] ?? null),
            ],
            [
                'city_id' => $data['city_id'] ?? null,
                'zone_id' => $data['zone_id'] ?? null,
                'area_id' => $data['area_id'] ?? null,
            ]
        );

        return $this->successResponse(
            $zone,
            'Mapped '.($zone->zone ? "{$zone->zone}, {$zone->city}" : $zone->city)." to {$courier->name}."
        );
    }

    public function destroyZone(int $id, int $zone): JsonResponse
    {
        CourierZone::where('courier_id', $id)->whereKey($zone)->firstOrFail()->delete();

        return $this->successResponse([], 'Mapping removed.');
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
