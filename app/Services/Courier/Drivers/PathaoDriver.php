<?php

namespace App\Services\Courier\Drivers;

use App\Exceptions\CourierException;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Courier\Consignment;
use App\Services\Courier\CourierDriver;
use App\Services\Courier\ZoneResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pathao Courier.
 *
 * Two steps rather than one: an OAuth token, then the booking. The token is
 * cached — Pathao rate-limits issuing them, and asking for a fresh one on
 * every parcel is how a busy afternoon starts failing.
 *
 * Pathao also wants a delivery zone and area id, which come from their own
 * lookup endpoints rather than from a free-text address. Where the shop has
 * not mapped one, the default zone saved with the credentials is used; a
 * parcel with no zone at all is refused here rather than by them, so the
 * message says something useful.
 *
 * Endpoints and field names are from Pathao's merchant API docs. Check them
 * against your own panel before going live.
 */
class PathaoDriver implements CourierDriver
{
    private const LIVE = 'https://api-hermes.pathao.com';

    private const SANDBOX = 'https://courier-api-sandbox.pathao.com';

    /** Their tokens last far longer; this only has to stop a stampede. */
    private const TOKEN_TTL = 3000;

    public function key(): string
    {
        return 'pathao';
    }

    public function label(): string
    {
        return 'Pathao Courier';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'client_id', 'label' => 'Client ID', 'secret' => true],
            ['name' => 'client_secret', 'label' => 'Client Secret', 'secret' => true],
            ['name' => 'username', 'label' => 'Merchant email', 'secret' => false],
            ['name' => 'password', 'label' => 'Merchant password', 'secret' => true],
            ['name' => 'store_id', 'label' => 'Store ID', 'secret' => false,
                'hint' => 'From Pathao’s merchant panel — the store parcels are collected from.'],
            ['name' => 'default_city_id', 'label' => 'Default city ID', 'secret' => false],
            ['name' => 'default_zone_id', 'label' => 'Default zone ID', 'secret' => false,
                'hint' => 'Used when an address has no zone mapped. Pathao refuses a booking without one.'],
        ];
    }

    public function createConsignment(Order $order, Courier $courier): Consignment
    {
        $credentials = $courier->credentials ?? [];

        foreach (['client_id', 'client_secret', 'username', 'password', 'store_id'] as $required) {
            if (blank($credentials[$required] ?? null)) {
                throw CourierException::notConfigured($courier->name);
            }
        }

        $base = $courier->is_sandbox ? self::SANDBOX : self::LIVE;
        $token = $this->token($courier, $credentials, $base);

        /*
         * The customer's city and zone, looked up in the shop's mapping and
         * falling back to the default saved with the credentials. Before there
         * was a mapping every parcel went out on that one default, which is
         * right for the shop's own district and wrong for the rest.
         */
        $ids = ZoneResolver::for($order, $courier);
        $zone = $ids['zone_id'];
        $city = $ids['city_id'];

        if (blank($zone) || blank($city)) {
            $address = $order->shipping_address ?? [];
            $place = trim(($address['zone'] ?? '').' '.($address['city'] ?? ''));

            throw CourierException::refused(
                $courier->name,
                'it needs a city and zone id, and there is no mapping for '
                    .($place !== '' ? '"'.$place.'"' : 'this address')
                    .' and no default set. Map the area, or set a default city and zone, under Couriers.',
            );
        }

        try {
            $response = Http::timeout(25)
                ->withToken($token)
                ->acceptJson()
                ->post($base.'/aladdin/api/v1/orders', [
                    'store_id' => $credentials['store_id'],
                    'merchant_order_id' => $order->order_number,
                    'recipient_name' => $order->recipient_name,
                    'recipient_phone' => $order->recipient_phone,
                    'recipient_address' => $order->formatted_shipping_address,
                    'recipient_city' => (int) $city,
                    'recipient_zone' => (int) $zone,
                    'delivery_type' => 48,   // 48 = normal, 12 = on-demand
                    'item_type' => 2,        // 2 = parcel, 1 = document
                    'item_quantity' => (int) $order->items->sum('quantity'),
                    'item_weight' => 0.5,
                    // Nothing to collect on a prepaid order.
                    'amount_to_collect' => $order->payment_status === 'paid'
                        ? 0
                        : (int) round((float) $order->total),
                    'item_description' => $order->items
                        ->map(fn ($i) => $i->display_name)
                        ->implode(', '),
                ]);
        } catch (\Throwable $e) {
            throw CourierException::unreachable($courier->name, $e->getMessage());
        }

        if ($response->failed()) {
            // A stale cached token is the likeliest failure; drop it so the
            // next attempt authenticates afresh rather than failing the same way.
            if ($response->status() === 401) {
                Cache::forget($this->tokenKey($courier));
            }

            throw CourierException::refused(
                $courier->name,
                $this->reasonFrom($response->json(), $response->status()),
                ['status' => $response->status(), 'body' => $response->json()]
            );
        }

        $body = $response->json();
        $tracking = $body['data']['consignment_id'] ?? null;

        if (blank($tracking)) {
            throw CourierException::refused(
                $courier->name,
                'the booking succeeded but no consignment id came back',
                ['body' => $body]
            );
        }

        return new Consignment((string) $tracking, $body);
    }

    /**
     * An access token, cached so a busy afternoon does not spend itself asking
     * for new ones.
     */
    private function token(Courier $courier, array $credentials, string $base): string
    {
        return Cache::remember($this->tokenKey($courier), self::TOKEN_TTL, function () use ($credentials, $base, $courier) {
            try {
                $response = Http::timeout(20)->acceptJson()->post($base.'/aladdin/api/v1/issue-token', [
                    'client_id' => $credentials['client_id'],
                    'client_secret' => $credentials['client_secret'],
                    'grant_type' => 'password',
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                ]);
            } catch (\Throwable $e) {
                throw CourierException::unreachable($courier->name, $e->getMessage());
            }

            $token = $response->json('access_token');

            if ($response->failed() || blank($token)) {
                throw CourierException::refused(
                    $courier->name,
                    'the credentials were not accepted — check the client id, secret and merchant login',
                    ['status' => $response->status()]
                );
            }

            return $token;
        });
    }

    private function tokenKey(Courier $courier): string
    {
        return "courier.pathao.token.{$courier->id}";
    }

    private function reasonFrom(?array $body, int $status): string
    {
        if (is_array($body)) {
            if (is_array($body['errors'] ?? null)) {
                $first = collect($body['errors'])->flatten()->first();

                if (filled($first)) {
                    return (string) $first;
                }
            }

            if (filled($body['message'] ?? null)) {
                return (string) $body['message'];
            }
        }

        return "HTTP {$status}";
    }
}
