<?php

namespace Tests\Feature\Tax;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\StockService;
use App\Support\ProfitAndLoss;
use App\Support\VatRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VAT, and the one decision that changes arithmetic rather than paperwork:
 * whether the prices on the shelf already contain it.
 *
 * Inclusive is the usual arrangement in Bangladeshi retail — the label is what
 * the customer hands over and the VAT is the portion the shop owes. Exclusive
 * adds it at checkout. Reading one as the other misstates both the customer's
 * total and the shop's revenue, so it is a setting and it is frozen onto every
 * order.
 */
class VatTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id, 'name' => 'Ryzen', 'slug' => 'ryzen',
            'price' => 1000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 600,
        ]]);

        // Delivery out of the way; VAT is charged on goods, not the courier fee.
        SiteSetting::set('shipping_inside_dhaka', '0', 'shipping');
        SiteSetting::flushCache(['shipping_inside_dhaka']);
    }

    private function vat(array $settings): void
    {
        foreach ($settings as $key => $value) {
            SiteSetting::set($key, (string) $value, 'tax');
        }

        SiteSetting::flushCache(array_keys($settings));
    }

    private function buy(int $qty = 1, ?string $coupon = null): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id, 'quantity' => $qty,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', array_filter([
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
            'coupon_code' => $coupon,
        ]))->assertStatus(201);

        return Order::latest('id')->first();
    }

    /** Nothing moves until a shop turns it on. */
    public function test_vat_is_off_until_it_is_switched_on(): void
    {
        $order = $this->buy(1);

        $this->assertFalse(VatRules::enabled());
        $this->assertSame(0.0, (float) $order->vat_amount);
        $this->assertSame(1000.0, (float) $order->total);
        $this->assertNull($order->vat_label);
    }

    /** Inclusive: 1,000 at 15% already holds 130.43. The customer pays 1,000. */
    public function test_inclusive_pricing_extracts_the_vat_without_changing_the_total(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 1]);

        $order = $this->buy(1);

        $this->assertSame(130.43, (float) $order->vat_amount);
        $this->assertSame(1000.0, (float) $order->total, 'Inclusive VAT must not add to the bill.');
        $this->assertTrue($order->vat_inclusive);
        $this->assertSame('Includes VAT @ 15%', $order->vat_label);
    }

    /** Exclusive: 1,000 at 15% adds 150. The customer pays 1,150. */
    public function test_exclusive_pricing_adds_the_vat_on_top(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 0]);

        $order = $this->buy(1);

        $this->assertSame(150.0, (float) $order->vat_amount);
        $this->assertSame(1150.0, (float) $order->total);
        $this->assertFalse($order->vat_inclusive);
        $this->assertSame('VAT @ 15%', $order->vat_label);
    }

    /** The discount comes off before the tax is worked out. */
    public function test_vat_is_charged_on_the_discounted_goods(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 0]);

        Coupon::create([
            'code' => 'TAKE200', 'discount_type' => 'fixed',
            'discount_value' => 200, 'is_active' => true,
        ]);

        $order = $this->buy(1, 'TAKE200');

        // 1,000 less 200 = 800, and 15% of 800 is 120.
        $this->assertSame(120.0, (float) $order->vat_amount);
        $this->assertSame(920.0, (float) $order->total);
    }

    /** Delivery is collected for the courier, so it is not taxed here. */
    public function test_delivery_is_not_taxed(): void
    {
        SiteSetting::set('shipping_inside_dhaka', '100', 'shipping');
        SiteSetting::flushCache(['shipping_inside_dhaka']);
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 0]);

        $order = $this->buy(1);

        $this->assertSame(150.0, (float) $order->vat_amount, 'The delivery fee was taxed.');
        $this->assertSame(1250.0, (float) $order->total);   // 1000 + 150 VAT + 100 delivery
    }

    /** An old invoice still has to reconcile after the rate moves. */
    public function test_the_rate_is_frozen_onto_the_order(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 0]);
        $order = $this->buy(1);

        $this->vat(['vat_rate' => 20]);

        $order = $order->fresh();

        $this->assertSame(15.0, (float) $order->vat_rate);
        $this->assertSame(150.0, (float) $order->vat_amount);
        $this->assertSame(1150.0, (float) $order->total);
    }

    /** Switching the shop to exclusive must not rewrite what an old order meant. */
    public function test_the_inclusive_flag_is_frozen_too(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 1]);
        $order = $this->buy(1);

        $this->vat(['vat_inclusive' => 0]);

        $this->assertTrue($order->fresh()->vat_inclusive);
        $this->assertSame('Includes VAT @ 15%', $order->fresh()->vat_label);
    }

    /**
     * The correctness point: VAT is collected for the government and owed to
     * it. Counting it as income would overstate revenue and profit by the rate
     * in every period.
     */
    public function test_vat_is_not_revenue(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 1]);
        $this->buy(1);

        $s = ProfitAndLoss::statement();

        $this->assertSame(869.57, $s['income']['goods'], 'VAT was counted as revenue.');
        $this->assertSame(130.43, $s['vat_collected']);
        // 869.57 earned less the 600 those units cost.
        $this->assertSame(269.57, $s['gross_profit']);
    }

    public function test_exclusive_vat_is_also_kept_out_of_revenue(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 0]);
        $this->buy(1);

        $s = ProfitAndLoss::statement();

        // The goods figure never contained the tax to begin with.
        $this->assertSame(1000.0, $s['income']['goods']);
        $this->assertSame(150.0, $s['vat_collected']);
        $this->assertSame(400.0, $s['gross_profit']);
    }

    /** The cart says the number before the customer commits to it. */
    public function test_the_cart_shows_the_vat(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_inclusive' => 0]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->getJson('/api/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.totals.vat', 150)
            ->assertJsonPath('data.totals.vat_inclusive', false)
            ->assertJsonPath('data.totals.total', 1150);
    }

    public function test_a_nonsense_rate_is_clamped_rather_than_trusted(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 'abc']);
        $this->assertSame(0.0, VatRules::rate());

        $this->vat(['vat_rate' => -5]);
        $this->assertSame(0.0, VatRules::rate());

        $this->vat(['vat_rate' => 500]);
        $this->assertSame(100.0, VatRules::rate());
    }

    /** The registration number belongs on the invoice it was issued under. */
    public function test_the_registration_number_reaches_the_invoice(): void
    {
        $this->vat(['vat_enabled' => 1, 'vat_rate' => 15, 'vat_number' => '004123456-0101']);

        $order = $this->buy(1);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get("/orders/{$order->id}/invoice")
            ->assertStatus(200)
            ->assertSee('004123456-0101');
    }
}
