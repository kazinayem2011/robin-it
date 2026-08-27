<?php

namespace App\Services\Courier\Drivers;

use App\Exceptions\CourierException;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Courier\Consignment;
use App\Services\Courier\CourierDriver;
use Illuminate\Support\Facades\Http;

/**
 * Steadfast Courier.
 *
 * The simplest of the three: two static keys in headers, one call to create
 * the order, and the consignment number comes straight back.
 *
 * The endpoint and field names below are the ones Steadfast documents for
 * their merchant API. Check them against your own panel before going live —
 * this is the part that changes without warning, and it is deliberately all in
 * one place so correcting it is a small edit.
 */
class SteadfastDriver implements CourierDriver
{
    private const BASE = 'https://portal.packzy.com/api/v1';

    public function key(): string
    {
        return 'steadfast';
    }

    public function label(): string
    {
        return 'Steadfast Courier';
    }

    public function credentialFields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key', 'secret' => true],
            ['name' => 'secret_key', 'label' => 'Secret Key', 'secret' => true],
        ];
    }

    public function createConsignment(Order $order, Courier $courier): Consignment
    {
        $credentials = $courier->credentials ?? [];

        foreach (['api_key', 'secret_key'] as $required) {
            if (blank($credentials[$required] ?? null)) {
                throw CourierException::notConfigured($courier->name);
            }
        }

        $address = $order->shipping_address ?? [];

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Api-Key' => $credentials['api_key'],
                    'Secret-Key' => $credentials['secret_key'],
                    'Content-Type' => 'application/json',
                ])
                ->post(self::BASE.'/create_order', [
                    // Their own reference for the parcel; the order number is
                    // what support will be asked about.
                    'invoice' => $order->order_number,
                    'recipient_name' => $order->recipient_name,
                    'recipient_phone' => $order->recipient_phone,
                    'recipient_address' => $order->formatted_shipping_address,
                    // Cash to collect on delivery. Zero on a prepaid order,
                    // and getting this wrong means the rider collects nothing
                    // or collects twice.
                    'cod_amount' => $order->payment_status === 'paid'
                        ? 0
                        : (float) $order->total,
                    'note' => $address['note'] ?? null,
                ]);
        } catch (\Throwable $e) {
            throw CourierException::unreachable($courier->name, $e->getMessage());
        }

        if ($response->failed()) {
            throw CourierException::refused(
                $courier->name,
                $this->reasonFrom($response->json(), $response->status()),
                ['status' => $response->status(), 'body' => $response->json()]
            );
        }

        $body = $response->json();
        $consignment = $body['consignment'] ?? [];

        // Their tracking page is keyed by tracking_code, not the numeric id.
        $tracking = $consignment['tracking_code'] ?? $consignment['consignment_id'] ?? null;

        if (blank($tracking)) {
            throw CourierException::refused(
                $courier->name,
                'the booking succeeded but no consignment number came back',
                ['body' => $body]
            );
        }

        return new Consignment((string) $tracking, $body);
    }

    /** Their errors arrive in more than one shape depending on what failed. */
    private function reasonFrom(?array $body, int $status): string
    {
        if (is_array($body)) {
            if (filled($body['message'] ?? null)) {
                return (string) $body['message'];
            }

            if (is_array($body['errors'] ?? null)) {
                $first = collect($body['errors'])->flatten()->first();

                if (filled($first)) {
                    return (string) $first;
                }
            }
        }

        return "HTTP {$status}";
    }
}
