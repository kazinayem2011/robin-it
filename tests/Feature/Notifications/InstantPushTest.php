<?php

namespace Tests\Feature\Notifications;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ShopNotifier;
use App\Services\StockService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bell's push goes out when the thing happens, not when a queue is next
 * worked.
 *
 * A notification's broadcast is queued by default, and the live shop's queue is
 * worked by the scheduler every five minutes — so a new order reached the
 * admin, and a status change reached the customer, up to five minutes late.
 * The test suite runs its queue synchronously, which is why nothing here
 * noticed: these tests put the queue back to what production uses.
 */
class InstantPushTest extends TestCase
{
    use RefreshDatabase;

    private PushSpy $pushes;

    private User $admin;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // What the live shop runs: a database queue nobody is working right now.
        config(['queue.default' => 'database']);

        // Captured, not $this: the manager rebinds the closure to itself.
        $spy = $this->pushes = new PushSpy;
        app(BroadcastManager::class)->extend('spy', fn () => $spy);
        config(['broadcasting.default' => 'spy', 'broadcasting.connections.spy' => ['driver' => 'spy']]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);
        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create(['phone' => '01712345678', 'email' => null]);

        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id, 'name' => 'Ryzen 7', 'slug' => 'ryzen-7-push',
            'price' => 30000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 1000,
        ]]);
    }

    private function placeOrder(): Order
    {
        $this->actingAs($this->customer)
            ->postJson('/api/cart', ['product_id' => $this->product->id, 'quantity' => 1])
            ->assertOk();
        $this->actingAs($this->customer)->postJson('/api/checkout', [
            'name' => 'Karim', 'phone' => '01712345678',
            'address' => 'House 12, Road 4, Dhanmondi', 'delivery_zone' => 'inside_dhaka',
        ])->assertCreated();

        return Order::latest('id')->firstOrFail();
    }

    private function queuedPushes(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%BroadcastEvent%')->count();
    }

    public function test_a_new_order_reaches_the_admin_at_once(): void
    {
        $this->placeOrder();

        $this->assertContains("private-App.Models.User.{$this->admin->id}", $this->pushes->channels());
        $this->assertSame(0, $this->queuedPushes(), 'The push waited in the queue.');
    }

    /** The case reported: the admin marks it processing and the customer hears nothing. */
    public function test_processing_reaches_the_customer_at_once_and_by_text(): void
    {
        $order = $this->placeOrder();
        $this->pushes->sent = [];
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertOk();

        $this->assertContains("private-App.Models.User.{$this->customer->id}", $this->pushes->channels());
        $this->assertSame(0, $this->queuedPushes());

        $texts = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['message'] ?? '')->filter();
        $this->assertTrue(
            $texts->contains(fn ($text) => str_contains($text, 'কনফার্ম') && str_contains($text, $order->order_number)),
            'No "order confirmed" text was sent: '.$texts->implode(' | ')
        );
    }

    /** Pusher being down is not the customer's problem. */
    public function test_a_failing_push_does_not_fail_the_checkout(): void
    {
        $this->pushes->failWith = new \RuntimeException('Pusher is down');

        $order = $this->placeOrder();

        // And the bell still has it, for the next page load.
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame('pending', $order->status);
    }

    /** Nobody is told about a change that rolled back. */
    public function test_nothing_is_pushed_from_a_transaction_that_rolls_back(): void
    {
        $order = $this->placeOrder();
        $this->pushes->sent = [];

        try {
            DB::transaction(function () use ($order) {
                app(ShopNotifier::class)->orderStatusChanged($order->load('user'), 'processing');

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->pushes->sent);
        $this->assertSame(0, $this->customer->notifications()->count());
    }
}

/** A broadcaster that remembers what it was asked to push, or refuses. */
class PushSpy implements Broadcaster
{
    /** @var list<array{channels: list<string>, event: string}> */
    public array $sent = [];

    public ?\Throwable $failWith = null;

    public function auth($request)
    {
        return true;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->sent[] = [
            'channels' => array_map(fn ($channel) => (string) $channel, $channels),
            'event' => $event,
        ];
    }

    /** @return list<string> */
    public function channels(): array
    {
        return array_merge(...array_column($this->sent, 'channels') ?: [[]]);
    }
}
