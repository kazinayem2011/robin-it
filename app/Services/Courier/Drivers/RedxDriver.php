<?php

namespace App\Services\Courier\Drivers;

use App\Exceptions\CourierException;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Courier\Consignment;
use App\Services\Courier\CourierDriver;
use Illuminate\Support\Facades\Http;

/**
 * RedX.
 *
 * A single long-lived access token in a header, and one call to create the
 * parcel. Like Pathao it wants an area id rather than a free-text address, so
 * a default is kept with the credentials.
 *
 * Endpoint and field names are from RedX's open API docs. Check them against
 * your own panel before going live.
 */
class RedxDriver implements CourierDriver
{
    private const LIVE = 'https://openapi.redx.com.bd/v1.0.0-beta';

    private const SANDBOX = 'https://sandbox.redx.com.bd/v1.0.0-beta';

    public function key(): string
    {
        return 'redx';
    }

    public function label(): string
    {
        return 'RedX';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'access_token', 'label' => 'API access token', 'secret' => true],
            ['name' => 'default_area_id', 'label' => 'Default delivery area ID', 'secret' => false,
                'hint' => 'From RedX’s area list. Used when an address has no area mapped.'],
            ['name' => 'pickup_store_id', 'label' => 'Pickup store ID', 'secret' => false],
        ];
    }

    public function createConsignment(Order $order, Courier $courier): Consignment
    {
        $credentials = $courier->credentials ?? [];

        if (blank($credentials['access_token'] ?? null)) {
            throw CourierException::notConfigured($courier->name);
        }

        $address = $order->shipping_address ?? [];
        $area = $address['redx_area_id'] ?? $credentials['default_area_id'] ?? null;

        if (blank($area)) {
            throw CourierException::refused(
                $courier->name,
                'it needs a delivery area id, and neither this address nor the courier settings have one. '
                    .'Set a default area under Couriers.',
            );
        }

        $base = $courier->is_sandbox ? self::SANDBOX : self::LIVE;

        try {
            $response = Http::timeout(25)
                ->withHeaders(['API-ACCESS-TOKEN' => 'Bearer '.$credentials['access_token']])
                ->acceptJson()
                ->post($base.'/parcel', [
                    'customer_name' => $order->recipient_name,
                    'customer_phone' => $order->recipient_phone,
                    'delivery_area_id' => (int) $area,
                    'customer_address' => $order->formatted_shipping_address,
                    'merchant_invoice_id' => $order->order_number,
                    'cash_collection_amount' => $order->payment_status === 'paid'
                        ? '0'
                        : (string) round((float) $order->total),
                    'parcel_weight' => 500,   // grams
                    'value' => (int) round((float) $order->subtotal),
                    'pickup_store_id' => $credentials['pickup_store_id'] ?? null,
                    'parcel_details_json' => $order->items->map(fn ($i) => [
                        'name' => $i->display_name,
                        'category' => 'general',
                        'value' => (int) round((float) $i->price),
                    ])->values()->all(),
                ]);
        } catch (\Throwable $e) {
            throw CourierException::unreachable($courier->name, $e->getMessage());
        }

        if ($response->failed()) {
            throw CourierException::refused(
                $courier->name,
                $response->json('message') ?? 'HTTP '.$response->status(),
                ['status' => $response->status(), 'body' => $response->json()]
            );
        }

        $tracking = $response->json('tracking_id');

        if (blank($tracking)) {
            throw CourierException::refused(
                $courier->name,
                'the booking succeeded but no tracking id came back',
                ['body' => $response->json()]
            );
        }

        return new Consignment((string) $tracking, $response->json() ?? []);
    }
}
