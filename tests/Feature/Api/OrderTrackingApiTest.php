<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTrackingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_track_existing_order_with_valid_phone(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-TEST123456',
            'subtotal' => 50000,
            'shipping_fee' => 60,
            'total' => 50060,
            'status' => 'processing',
            'payment_method' => 'COD',
            'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'Kazi Nayem',
                'phone' => '01711223344',
                'street_address' => 'House 12, Road 5, Dhanmondi',
                'city' => 'Dhaka',
            ],
        ]);

        $response = $this->postJson('/'.ApiEndpoints::API_PREFIX.'/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => 'ORD-TEST123456',
            'phone' => '01711223344',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.order_number', 'ORD-TEST123456')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.current_step', 2);
    }

    public function test_returns_404_when_tracking_order_with_mismatched_phone(): void
    {
        Order::create([
            'order_number' => 'ORD-TEST999999',
            'subtotal' => 20000,
            'shipping_fee' => 60,
            'total' => 20060,
            'status' => 'pending',
            'payment_method' => 'COD',
            'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'John Doe',
                'phone' => '01811223344',
                'street_address' => 'Gulshan 2',
                'city' => 'Dhaka',
            ],
        ]);

        $response = $this->postJson('/'.ApiEndpoints::API_PREFIX.'/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => 'ORD-TEST999999',
            'phone' => '01700000000',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('error', true);
    }
}
