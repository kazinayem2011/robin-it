<?php

namespace Tests\Feature\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderPlaced;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The endpoint behind the bell.
 *
 * The socket is the nudge; this is the record. Everything is scoped by the
 * relation on the signed-in user, so there is no id in a request that could
 * reach somebody else's.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);

        return $user->refresh();
    }

    private function order(): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT),
            'session_id' => str_repeat('s', 40),
            'status' => 'pending',
            'subtotal' => 12500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 12500,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
    }

    public function test_it_lists_what_is_waiting_and_counts_the_unread(): void
    {
        $admin = $this->admin();
        $admin->notify(new OrderPlaced($this->order()));

        $data = $this->actingAs($admin)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['notifications']);
        $this->assertSame(1, $data['unread']);
        $this->assertStringContainsString('New order', $data['notifications'][0]['title']);
        $this->assertFalse($data['notifications'][0]['read']);
    }

    public function test_one_can_be_marked_read(): void
    {
        $admin = $this->admin();
        $admin->notify(new OrderPlaced($this->order()));
        $id = $admin->notifications()->sole()->id;

        $this->actingAs($admin)
            ->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->assertNotNull($admin->notifications()->sole()->read_at);
    }

    public function test_all_can_be_marked_read_at_once(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 3; $i++) {
            $admin->notify(new OrderPlaced($this->order()));
        }

        $this->actingAs($admin)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(0, $admin->unreadNotifications()->count());
    }

    /** The boundary that matters: one person's bell is not another's. */
    public function test_it_never_shows_somebody_elses(): void
    {
        $mine = $this->admin();
        $theirs = $this->admin();

        $theirs->notify(new OrderPlaced($this->order()));

        $data = $this->actingAs($mine)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $data['notifications']);
        $this->assertSame(0, $data['unread']);
    }

    public function test_marking_someone_elses_read_does_nothing(): void
    {
        $mine = $this->admin();
        $theirs = $this->admin();

        $theirs->notify(new OrderPlaced($this->order()));
        $id = $theirs->notifications()->sole()->id;

        $this->actingAs($mine)->postJson("/api/notifications/{$id}/read")->assertOk();

        $this->assertNull($theirs->notifications()->sole()->read_at);
    }

    public function test_a_guest_gets_nothing(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    /** A customer has a bell too — it is how they hear about their own order. */
    public function test_a_customer_can_read_their_own(): void
    {
        $customer = User::factory()->create();
        $customer->notify(new OrderPlaced($this->order()));

        $this->actingAs($customer)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread', 1);
    }
}
