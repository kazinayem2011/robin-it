<?php

namespace Tests\Feature\Refunds;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\StockService;
use App\Support\ProfitAndLoss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money given back, recorded as an event rather than a flag.
 *
 * `payment_status = 'refunded'` was the whole record: no amount, no date, no
 * method, no reason, and no way to express giving back part of an order —
 * which is what happens when one item of three comes back.
 */
class RefundTest extends TestCase
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
            'product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 600,
        ]]);
    }

    private function order(int $qty = 3): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id, 'quantity' => $qty,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 1000,
            'method' => 'bkash',
            'reason' => 'returned',
            'refunded_on' => now()->toDateString(),
        ], $overrides);
    }

    public function test_a_refund_records_what_actually_happened(): void
    {
        $order = $this->order();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'reference' => 'TRX8891',
                'note' => 'One unit came back damaged',
            ]))->assertStatus(201);

        $refund = Refund::first();

        $this->assertSame(1000.0, $refund->amount);
        $this->assertSame('bkash', $refund->method);
        $this->assertSame('returned', $refund->reason);
        $this->assertSame('TRX8891', $refund->reference);
        // Who authorised it, so it can be asked about later.
        $this->assertSame($admin->id, $refund->user_id);
    }

    /** The thing a flag could never express. */
    public function test_part_of_an_order_can_be_given_back(): void
    {
        $order = $this->order();               // 3 x 1000 + delivery
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload(['amount' => 1000]))
            ->assertStatus(201);

        $order->refresh();

        $this->assertSame(1000.0, $order->refunded_total);
        $this->assertFalse($order->isFullyRefunded());
        // Still owed to the customer if they returned the rest.
        $this->assertGreaterThan(0, $order->refundable_amount);
        $this->assertNotSame('refunded', $order->payment_status);
    }

    public function test_several_refunds_add_up(): void
    {
        $order = $this->order();
        $admin = $this->admin();

        foreach ([1000, 500] as $amount) {
            $this->actingAs($admin)
                ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload(['amount' => $amount]))
                ->assertStatus(201);
        }

        $this->assertSame(1500.0, $order->fresh()->refunded_total);
    }

    public function test_refunding_the_whole_order_marks_it_refunded(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'amount' => $order->total,
            ]))->assertStatus(201);

        $order->refresh();

        $this->assertTrue($order->isFullyRefunded());
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame(0.0, $order->refundable_amount);
    }

    /** Giving back more than was taken is always a mistake. */
    public function test_it_refuses_to_give_back_more_than_was_paid(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'amount' => $order->total + 1,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error', true);

        $this->assertSame(0, Refund::count());
    }

    public function test_it_refuses_a_second_refund_past_the_total(): void
    {
        $order = $this->order();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund",
            $this->payload(['amount' => $order->total]))->assertStatus(201);

        $response = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund",
            $this->payload(['amount' => 1]))->assertStatus(422);

        $this->assertStringContainsString('already been refunded in full', $response->json('message'));
        $this->assertSame(1, Refund::count());
    }

    /** A typo should be undone, not papered over with a correcting entry. */
    public function test_a_refund_can_be_removed_and_the_order_recovers(): void
    {
        $order = $this->order();
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund",
            $this->payload(['amount' => $order->total]))->json('data.id');

        $this->assertSame('refunded', $order->fresh()->payment_status);

        $this->actingAs($admin)->deleteJson("/api/admin/refunds/{$id}")->assertStatus(200);

        $order->refresh();

        $this->assertSame(0.0, $order->refunded_total);
        $this->assertNotSame('refunded', $order->payment_status);
    }

    /**
     * On a cash-on-delivery shop the common case: the parcel came back before
     * the rider took any money, so nothing is actually refunded — but the
     * order still has to say the customer owes nothing.
     */
    public function test_cash_never_collected_is_recorded_without_money_moving(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'amount' => $order->total,
                'method' => 'cod_not_collected',
                'reason' => 'undelivered',
            ]))->assertStatus(201);

        $this->assertSame('refunded', $order->fresh()->payment_status);
        // Recorded, but it is not a payout.
        $this->assertSame(0, Refund::settled()->count());
        $this->assertSame(1, Refund::count());
    }

    public function test_a_refund_cannot_be_dated_in_the_future(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'refunded_on' => now()->addWeek()->toDateString(),
            ]))->assertStatus(422);
    }

    public function test_a_customer_cannot_refund_their_own_order(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Refund::count());
    }

    // ── What it does to the accounts ────────────────────────────────────────

    /** A refund is revenue handed back, so profit falls by it. */
    public function test_a_refund_comes_off_the_profit(): void
    {
        $order = $this->order(1);              // 1,000 sold, 600 cost

        $before = ProfitAndLoss::statement();
        $this->assertSame(400.0, $before['gross_profit']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload(['amount' => 250]))
            ->assertStatus(201);

        $after = ProfitAndLoss::statement();

        $this->assertSame(250.0, $after['refunded']);
        $this->assertSame(150.0, $after['gross_profit']);
    }

    /** Nothing was ever collected, so nothing was handed back. */
    public function test_cash_never_collected_does_not_dent_the_profit(): void
    {
        $order = $this->order(1);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'amount' => 250, 'method' => 'cod_not_collected',
            ]))->assertStatus(201);

        $this->assertSame(0.0, ProfitAndLoss::statement()['refunded']);
        $this->assertSame(400.0, ProfitAndLoss::statement()['gross_profit']);
    }

    /**
     * A refund belongs to the month the money moved, not the month of the
     * sale — that is the month the shop was out of pocket.
     */
    public function test_a_refund_is_counted_in_the_period_it_was_paid(): void
    {
        $order = $this->order(1);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/orders/{$order->id}/refund", $this->payload([
                'amount' => 250,
                'refunded_on' => now()->subMonths(2)->toDateString(),
            ]))->assertStatus(201);

        $thisMonth = ProfitAndLoss::statement(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(0.0, $thisMonth['refunded'], 'An older refund leaked into this month.');
    }

    public function test_the_refunds_screen_totals_what_actually_left(): void
    {
        $order = $this->order();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund",
            $this->payload(['amount' => 500, 'method' => 'bkash']))->assertStatus(201);
        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund",
            $this->payload(['amount' => 500, 'method' => 'cod_not_collected']))->assertStatus(201);

        $props = $this->actingAs($admin)->get('/admin/refunds')
            ->assertStatus(200)->viewData('page')['props'];

        $this->assertCount(2, $props['refunds']['data']);
        // Only the bKash one was money leaving the business.
        $this->assertSame(500.0, $props['total']);
    }
}
