<?php

namespace Tests\Feature\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderPlaced;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The wiring between a notification and the browser.
 *
 * None of this fails loudly when it is wrong. The socket connects, the private
 * channel authorises, and the bell simply never moves — which looks exactly
 * like Pusher not being switched on rather than like a bug.
 *
 * What actually broke it in the end was neither of these: BroadcastNotification-
 * Created implements ShouldBroadcast, so it goes through the queue, and with no
 * worker running the message never left the jobs table. Worth knowing when the
 * bell goes quiet — check the queue before the credentials.
 */
class BroadcastWiringTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT),
            'session_id' => str_repeat('s', 40),
            'status' => 'pending',
            'subtotal' => 9900, 'shipping_fee' => 0, 'discount' => 0, 'total' => 9900,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);

        return $user->refresh();
    }

    /**
     * The notification reaches the broadcast channel at all.
     *
     * `via()` returning only 'database' would leave the bell correct on a
     * reload and dead in real time — the failure that looks like Pusher simply
     * not being configured.
     */
    public function test_it_is_broadcast_as_well_as_stored(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $admin = $this->admin();
        $admin->notify(new OrderPlaced($this->order()));

        Event::assertDispatched(BroadcastNotificationCreated::class);
        $this->assertSame(1, $admin->notifications()->count());
    }

    /** And goes to that user's own private channel, nobody else's. */
    public function test_it_broadcasts_on_the_recipients_private_channel(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $admin = $this->admin();
        $admin->notify(new OrderPlaced($this->order()));

        Event::assertDispatched(
            BroadcastNotificationCreated::class,
            function (BroadcastNotificationCreated $event) use ($admin) {
                $channels = array_map('strval', $event->broadcastOn());

                return $channels === ['private-App.Models.User.'.$admin->id];
            }
        );
    }

    /** The payload the bell draws itself from travels with it. */
    public function test_the_broadcast_carries_the_payload(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $admin = $this->admin();
        $admin->notify(new OrderPlaced($this->order()));

        Event::assertDispatched(
            BroadcastNotificationCreated::class,
            function (BroadcastNotificationCreated $event) {
                foreach (['kind', 'title', 'body', 'url', 'icon'] as $key) {
                    if (! array_key_exists($key, $event->data)) {
                        return false;
                    }
                }

                return str_contains($event->data['title'], 'New order');
            }
        );
    }
}
