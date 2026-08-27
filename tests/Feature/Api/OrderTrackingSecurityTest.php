<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The tracking endpoint matched phone numbers with str_ends_with in both
 * directions, so a single digit passed the ownership check and returned the
 * customer's name, mobile number and street address.
 */
class OrderTrackingSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_PHONE = '01712345678';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function placeOrder(): string
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Intel Core i9',
            'slug' => 'intel-core-i9',
            'price' => 50000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $orderNumber = $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CHECKOUT, [
            'name' => 'Rahim Chowdhury',
            'phone' => self::REAL_PHONE,
            'street_address' => 'House 45, Road 7, Gulshan 2',
            'city' => 'Dhaka',
        ])->json('data.order_number');

        /*
         * Signed out again before anything is tracked.
         *
         * actingAs lasts the rest of the test, so every lookup below was
         * arriving as the customer who placed the order — who is the one
         * person entitled to see it. This file is about everybody else, and a
         * guest is who it means. Without this, a wrong phone number opened the
         * order and the test that says otherwise passed only because
         * validation had already turned the guess away.
         */
        Auth::logout();

        return $orderNumber;
    }

    public static function partialPhoneProvider(): array
    {
        return [
            'single digit' => ['8'],
            'two digits' => ['78'],
            'last four' => ['5678'],
            'last six' => ['345678'],
            'all but the operator prefix' => ['12345678'],
            'nine digits' => ['712345678'],
        ];
    }

    #[DataProvider('partialPhoneProvider')]
    public function test_partial_phone_numbers_are_rejected(string $guess): void
    {
        $orderNumber = $this->placeOrder();

        $response = $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => $orderNumber,
            'phone' => $guess,
        ]);

        $this->assertContains(
            $response->status(),
            [404, 422],
            "Partial phone '{$guess}' must not authorise the lookup."
        );

        $this->assertNull($response->json('data.shipping_address'));
        $response->assertJsonMissing(['street_address' => 'House 45, Road 7, Gulshan 2']);
    }

    public function test_a_different_valid_phone_is_rejected(): void
    {
        $orderNumber = $this->placeOrder();

        $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => $orderNumber,
            'phone' => '01998887777',
        ])->assertStatus(404)->assertJsonPath('error', true);
    }

    public function test_the_correct_full_phone_returns_the_order(): void
    {
        $orderNumber = $this->placeOrder();

        $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => $orderNumber,
            'phone' => self::REAL_PHONE,
        ])->assertStatus(200)
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.order_number', $orderNumber)
            ->assertJsonPath('data.shipping_address.name', 'Rahim Chowdhury');
    }

    public function test_the_same_number_in_international_format_is_accepted(): void
    {
        $orderNumber = $this->placeOrder();

        $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => $orderNumber,
            'phone' => '+8801712345678',
        ])->assertStatus(200)->assertJsonPath('data.order_number', $orderNumber);
    }

    /**
     * Written without its leading zero, which is the same number.
     *
     * This used to sit among the partial guesses above, from the days when the
     * endpoint matched on a suffix and any tail of the number would do. It is
     * not a partial one: every Bangladeshi mobile begins 01, so the zero
     * carries no information and dropping it narrows nothing — the nine digits
     * that identify the subscriber are all still there, and the comparison is
     * an exact one. What invites the mistake is the app's own display,
     * "+880 1712-345678", which puts the country code and the number on either
     * side of a space and so gets copied in halves.
     *
     * The genuinely partial forms above stay refused.
     */
    public function test_the_same_number_without_its_leading_zero_is_accepted(): void
    {
        $orderNumber = $this->placeOrder();

        $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => $orderNumber,
            'phone' => '1712-345678',
        ])->assertStatus(200)->assertJsonPath('data.order_number', $orderNumber);
    }

    public function test_unknown_and_wrong_phone_are_indistinguishable(): void
    {
        $orderNumber = $this->placeOrder();

        $wrongPhone = $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => $orderNumber,
            'phone' => '01998887777',
        ]);

        $noSuchOrder = $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
            'order_number' => 'ORD-DOESNOTEXIST',
            'phone' => '01998887777',
        ]);

        // Identical responses, so the endpoint can't confirm which orders exist.
        $this->assertSame($wrongPhone->status(), $noSuchOrder->status());
        $this->assertSame($wrongPhone->json('message'), $noSuchOrder->json('message'));
    }

    public function test_tracking_is_rate_limited(): void
    {
        $orderNumber = $this->placeOrder();

        $statuses = [];
        for ($i = 0; $i < 14; $i++) {
            $statuses[] = $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [
                'order_number' => $orderNumber,
                'phone' => '019988877'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ])->status();
        }

        $this->assertContains(429, $statuses, 'Brute-forcing the tracking endpoint must be throttled.');
    }
}
