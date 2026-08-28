<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Refund;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money received against an order, in whole or in part.
 *
 * An order carried a payment_status of unpaid or paid and nothing else — a
 * flag, with no amount, date or method behind it — so a customer who put
 * ৳20,000 down on a ৳2,45,000 build was recorded exactly like one who had paid
 * nothing, and there was nowhere to look up what the shop was owed.
 */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->order = Order::create([
            'order_number' => 'ORD-PAYTEST',
            'session_id' => str_repeat('a', 40),
            'status' => 'pending',
            'subtotal' => 100000,
            'shipping_fee' => 0,
            'discount' => 0,
            'total' => 100000,
            'payment_method' => 'COD',
            'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01711000000', 'city' => 'Dhaka'],
        ]);
    }

    private function staff(string $role = Roles::ACCOUNTANT): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function pay(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff())
            ->postJson("/api/admin/orders/{$this->order->id}/payment", $payload + [
                'method' => 'cash',
            ]);
    }

    // --- the amounts ----------------------------------------------------

    public function test_a_new_order_owes_the_whole_total(): void
    {
        $this->assertSame(0.0, $this->order->amount_paid);
        $this->assertSame(100000.0, $this->order->amount_due);
        $this->assertSame('unpaid', $this->order->payment_state);
    }

    public function test_a_deposit_leaves_the_rest_owing(): void
    {
        $this->pay(['amount' => 20000, 'method' => 'bkash', 'reference' => 'TRX1'])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_due', fn ($due) => (float) $due === 80000.0);

        $this->order->refresh();

        $this->assertSame(20000.0, $this->order->amount_paid);
        $this->assertSame(80000.0, $this->order->amount_due);
        // Neither unpaid nor paid; calling it either loses money.
        $this->assertSame('partial', $this->order->payment_state);
        $this->assertSame('partial', $this->order->payment_status);
    }

    public function test_paying_the_balance_settles_it(): void
    {
        $this->pay(['amount' => 20000])->assertStatus(201);
        $this->pay(['amount' => 80000])->assertStatus(201);

        $this->order->refresh();

        $this->assertSame(0.0, $this->order->amount_due);
        $this->assertSame('paid', $this->order->payment_state);
        $this->assertSame('paid', $this->order->payment_status);
        $this->assertTrue($this->order->isFullyPaid());
    }

    // --- what it refuses ------------------------------------------------

    public function test_more_than_is_owed_is_refused(): void
    {
        $this->pay(['amount' => 100001])->assertStatus(422);

        $this->assertSame(0.0, $this->order->fresh()->amount_paid);
    }

    public function test_paying_again_once_settled_is_refused(): void
    {
        $this->pay(['amount' => 100000])->assertStatus(201);

        $this->pay(['amount' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already paid in full'));
    }

    public function test_zero_is_not_a_payment(): void
    {
        $this->pay(['amount' => 0])->assertStatus(422);
    }

    public function test_money_cannot_be_received_in_the_future(): void
    {
        $this->pay([
            'amount' => 1000,
            'received_on' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_an_unknown_method_is_refused(): void
    {
        $this->actingAs($this->staff())
            ->postJson("/api/admin/orders/{$this->order->id}/payment", [
                'amount' => 1000, 'method' => 'crypto',
            ])->assertStatus(422);
    }

    // --- corrections ----------------------------------------------------

    /**
     * A payment taken in error is corrected, not edited.
     *
     * The same shape as the stock ledger and the refunds beside it: history
     * stays, and the correction is part of it.
     */
    public function test_a_payment_taken_in_error_is_corrected_by_a_negative_row(): void
    {
        $this->pay(['amount' => 50000])->assertStatus(201);
        $this->pay(['amount' => -45000, 'note' => 'Typed 50000 instead of 5000'])
            ->assertStatus(201);

        $this->order->refresh();

        $this->assertSame(5000.0, $this->order->amount_paid);
        $this->assertSame(95000.0, $this->order->amount_due);
        // Both rows are kept.
        $this->assertCount(2, $this->order->payments);
    }

    /** A correction is not a back door to giving money out. */
    public function test_a_correction_cannot_take_the_total_below_zero(): void
    {
        $this->pay(['amount' => 5000])->assertStatus(201);

        $this->pay(['amount' => -6000])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'record a refund'));

        $this->assertSame(5000.0, $this->order->fresh()->amount_paid);
    }

    // --- refunds --------------------------------------------------------

    /**
     * Money given back was not kept, so it is owed again.
     */
    public function test_a_refund_puts_the_money_back_on_the_bill(): void
    {
        $this->pay(['amount' => 100000])->assertStatus(201);
        $this->assertSame('paid', $this->order->fresh()->payment_state);

        Refund::create([
            'order_id' => $this->order->id,
            'amount' => 30000,
            'method' => 'bkash',
            'reason' => 'goodwill',
            'refunded_on' => now()->toDateString(),
        ]);

        $this->order->refresh();

        $this->assertSame(100000.0, $this->order->amount_paid);
        $this->assertSame(30000.0, $this->order->refunded_total);
        $this->assertSame(30000.0, $this->order->amount_due);
        $this->assertSame('partial', $this->order->payment_state);
    }

    /** An overpayment is money to give back, not a debt owed to the shop. */
    public function test_due_never_goes_negative(): void
    {
        OrderPayment::create([
            'order_id' => $this->order->id,
            'amount' => 150000,
            'method' => 'cash',
            'received_by_name' => 'Someone',
            'received_on' => now()->toDateString(),
        ]);

        $this->assertSame(0.0, $this->order->fresh()->amount_due);
        $this->assertSame('paid', $this->order->fresh()->payment_state);
    }

    // --- who may do it --------------------------------------------------

    public function test_taking_money_belongs_to_whoever_handles_refunds(): void
    {
        $this->pay(['amount' => 1000], $this->staff(Roles::ACCOUNTANT))->assertStatus(201);

        // A storekeeper receives deliveries; the till is not theirs.
        $this->pay(['amount' => 1000], $this->staff(Roles::STOREKEEPER))->assertStatus(403);
    }

    public function test_the_payment_records_who_took_it(): void
    {
        $staff = $this->staff();
        $this->pay(['amount' => 1000], $staff)->assertStatus(201);

        $payment = $this->order->payments()->sole();

        $this->assertSame($staff->id, $payment->user_id);
        // Copied, so it survives the account being removed.
        $this->assertSame($staff->name, $payment->received_by_name);
    }

    /**
     * A refunded order keeps saying so.
     *
     * "Refunded" is a statement about money given back and outranks how much
     * happens to be outstanding today.
     */
    public function test_a_refunded_order_keeps_its_status(): void
    {
        $this->order->forceFill(['payment_status' => 'refunded'])->save();

        app(OrderPaymentService::class)->syncStatus($this->order->fresh());

        $this->assertSame('refunded', $this->order->fresh()->payment_status);
    }
}
