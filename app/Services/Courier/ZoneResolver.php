<?php

namespace App\Services\Courier;

use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Order;

/**
 * Turning "Dhanmondi, Dhaka" into the numbers a courier books against.
 *
 * Pathao and RedX do not take a written address; they take ids from their own
 * area lists. Without a mapping every parcel went out on the single default
 * saved with the credentials, which is right for the shop's own city and wrong
 * for the other sixty-three districts — and wrong quietly, as a rider in the
 * wrong place rather than as an error anyone sees.
 *
 * Three places an id can come from, most specific first:
 *
 *   The order itself, if somebody put one there by hand. A staff member who
 *   has looked the address up on the courier's own panel knows better than any
 *   table here.
 *
 *   The mapping, matched on what the customer typed. City and zone together
 *   first, then the city on its own — so a shop can map Dhaka once and refine
 *   Dhanmondi later without the two fighting.
 *
 *   The courier's default, which is the old behaviour and still the right
 *   answer for a shop that only delivers locally.
 */
class ZoneResolver
{
    /**
     * The ids to book this parcel with.
     *
     * @return array{city_id: ?string, zone_id: ?string, area_id: ?string}
     */
    public static function for(Order $order, Courier $courier): array
    {
        $address = $order->shipping_address ?? [];
        $credentials = $courier->credentials ?? [];

        $mapped = self::mappingFor(
            $courier,
            $address['city'] ?? null,
            $address['zone'] ?? null
        );

        return [
            'city_id' => self::first([
                $address['pathao_city_id'] ?? null,
                $mapped?->city_id,
                $credentials['default_city_id'] ?? null,
            ]),
            'zone_id' => self::first([
                $address['pathao_zone_id'] ?? null,
                $mapped?->zone_id,
                $credentials['default_zone_id'] ?? null,
            ]),
            'area_id' => self::first([
                $address['redx_area_id'] ?? null,
                $mapped?->area_id,
                $credentials['default_area_id'] ?? null,
            ]),
        ];
    }

    /**
     * The best mapping for a place, or null.
     *
     * The city-and-zone row wins over the city-only row. A shop that has mapped
     * Dhaka as a whole and then mapped Dhanmondi within it means the second to
     * be used for Dhanmondi, not to be one of two equally good answers.
     */
    public static function mappingFor(Courier $courier, ?string $city, ?string $zone): ?CourierZone
    {
        $city = CourierZone::normalise($city);

        if (! $city) {
            return null;
        }

        $zone = CourierZone::normalise($zone);

        if ($zone) {
            $exact = CourierZone::where('courier_id', $courier->id)
                ->where('city', $city)
                ->where('zone', $zone)
                ->first();

            if ($exact) {
                return $exact;
            }
        }

        return CourierZone::where('courier_id', $courier->id)
            ->where('city', $city)
            ->whereNull('zone')
            ->first();
    }

    /**
     * The first of these worth using.
     *
     * blank() rather than ??, because a mapping row can carry an empty string
     * for an id the courier does not use — RedX has no zone, Pathao has no
     * area — and an empty string is not an answer.
     *
     * @param  array<int, mixed>  $candidates
     */
    private static function first(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
