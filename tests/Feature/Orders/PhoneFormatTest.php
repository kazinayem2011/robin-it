<?php

namespace Tests\Feature\Orders;

use App\Helpers\PhoneHelper;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The app must accept the phone number it prints.
 *
 * An order confirmation shows "+880 1711-000000". Pasting that back into order
 * tracking was refused on the space and the hyphen, and so was the half after
 * the space, which is what people actually copy. Everything downstream already
 * normalised the number — validation just ran first, on the raw text.
 */
class PhoneFormatTest extends TestCase
{
    use RefreshDatabase;

    private const STORED = '01711000000';

    private function order(): Order
    {
        return Order::create([
            'order_number' => 'ORD-TESTTRACK',
            'user_id' => null,
            'session_id' => str_repeat('a', 40),
            'status' => 'pending',
            'subtotal' => 1000,
            'shipping_fee' => 60,
            'discount' => 0,
            'total' => 1060,
            'payment_method' => 'COD',
            'payment_status' => 'pending',
            'shipping_address' => [
                'name' => 'Rahim Chowdhury',
                'phone' => self::STORED,
                'city' => 'Dhaka',
                'street_address' => 'House 12, Road 4',
            ],
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writtenForms(): array
    {
        return [
            'as stored' => ['01711000000'],
            'as the app prints it' => ['+880 1711-000000'],
            'the half people copy' => ['1711-000000'],
            'without the leading zero' => ['1711000000'],
            'with the country code' => ['+8801711000000'],
            'country code, no plus' => ['8801711000000'],
            'spaced' => ['01711 000000'],
            'hyphenated' => ['01711-000000'],
            'bracketed' => ['(017) 1100 0000'],
        ];
    }

    #[DataProvider('writtenForms')]
    public function test_a_number_is_tracked_however_it_is_written(string $written): void
    {
        $this->order();

        $this->postJson('/api/orders/track', [
            'order_number' => 'ORD-TESTTRACK',
            'phone' => $written,
        ])->assertSuccessful()->assertJsonPath('data.status', 'pending');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusals(): array
    {
        return [
            'too short' => ['0171100000'],
            'too long' => ['017110000001'],
            'not a mobile prefix' => ['01211000000'],
            'letters' => ['not-a-number'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_number_that_is_not_one_is_still_refused(string $written): void
    {
        $this->order();

        $this->postJson('/api/orders/track', [
            'order_number' => 'ORD-TESTTRACK',
            'phone' => $written,
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /** Someone else's number must not open the order, however it is written. */
    public function test_a_different_number_is_not_let_through(): void
    {
        $this->order();

        $this->postJson('/api/orders/track', [
            'order_number' => 'ORD-TESTTRACK',
            'phone' => '+880 1811-000000',
        ])->assertStatus(404);
    }

    /**
     * Whatever was typed, one form reaches the database.
     *
     * Otherwise the same customer is two customers, and tracking an order
     * placed as "+880 1711-000000" would fail against a stored "01711000000".
     */
    public function test_checkout_stores_the_canonical_number(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $product = Product::create([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]
            )->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-phone',
            'price' => 5000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])
            ->assertSuccessful();

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury',
            'phone' => '+880 1711-000000',
            'city' => 'Dhaka',
            'zone' => 'Dhanmondi',
            'street_address' => 'House 12, Road 4',
        ])->assertSuccessful();

        $order = Order::latest('id')->first();

        $this->assertSame(self::STORED, $order->shipping_address['phone']);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function normalisations(): array
    {
        return [
            'display form' => ['+880 1711-000000', '01711000000'],
            'copied half' => ['1711-000000', '01711000000'],
            'bare ten digits' => ['1711000000', '01711000000'],
            'country code' => ['8801711000000', '01711000000'],
            'already canonical' => ['01711000000', '01711000000'],
            // Left alone rather than quietly rewritten, so the validator can
            // say what is wrong with it.
            'too short' => ['0171100000', '0171100000'],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('normalisations')]
    public function test_the_normaliser_reduces_a_number_to_one_form(string $in, ?string $out): void
    {
        $this->assertSame($out, PhoneHelper::normalizeBdPhone($in));
    }

    /**
     * A landline or a mistyped number of ten digits must not gain a zero.
     *
     * Only 1[3-9]XXXXXXXX can be a mobile missing its leading zero.
     */
    public function test_ten_digits_that_are_not_a_mobile_are_left_alone(): void
    {
        $this->assertSame('1211000000', PhoneHelper::normalizeBdPhone('1211-000000'));
        $this->assertFalse(PhoneHelper::isValidBdPhone('1211-000000'));
    }
}
