<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockNotification;
use App\Models\User;
use App\Notifications\StockRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The shop's side of "Notify me".
 *
 * Customers could ask and were emailed when stock returned, but nobody at the
 * shop could see the list or was told a request had been made. These cover the
 * list, the notification, and the count beside the product.
 */
class StockRequestAdminTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'RTX 4090 ASUS ROG'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => 250000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function ask(Product $product, string $email)
    {
        return $this->postJson('/api/stock-notifications', [
            'product_id' => $product->id,
            'email' => $email,
        ]);
    }

    public function test_the_stock_keeper_is_told_when_someone_asks(): void
    {
        Notification::fake();
        $keeper = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->ask($product, 'a@example.com')->assertCreated();

        Notification::assertSentTo($keeper, StockRequested::class, function ($n) use ($product) {
            return $n->product->is($product) && $n->waiting === 1;
        });
    }

    /* Asking again while already waiting is reassurance, not a new person. */
    public function test_asking_twice_does_not_tell_the_shop_twice(): void
    {
        Notification::fake();
        $keeper = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->ask($product, 'a@example.com');
        $this->ask($product, 'a@example.com');

        Notification::assertSentToTimes($keeper, StockRequested::class, 1);
    }

    /* A customer is not told about someone else wanting the same thing. */
    public function test_customers_are_not_told(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => 'customer']);

        $this->ask($this->product(), 'a@example.com');

        Notification::assertNotSentTo($customer, StockRequested::class);
    }

    public function test_the_list_puts_the_most_wanted_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $quiet = $this->product('Quiet mouse');
        $wanted = $this->product('Wanted card');

        $this->ask($quiet, 'one@example.com');
        foreach (['a', 'b', 'c'] as $who) {
            $this->ask($wanted, "{$who}@example.com");
        }

        $this->actingAs($admin)
            ->get('/admin/stock/requests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Stock/Requests')
                ->where('rows.0.product', 'Wanted card')
                ->where('rows.0.waiting', 3)
                ->where('rows.1.product', 'Quiet mouse')
                ->where('totals.waiting', 4)
                ->where('totals.products', 2)
                ->has('rows.0.requests', 3));
    }

    /* Once told, a request leaves the waiting list but can still be looked up. */
    public function test_told_requests_leave_the_waiting_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();
        $this->ask($product, 'a@example.com');
        StockNotification::query()->update(['notified_at' => now()]);

        $this->actingAs($admin)->get('/admin/stock/requests')
            ->assertInertia(fn ($page) => $page->has('rows', 0));

        $this->actingAs($admin)->get('/admin/stock/requests?told=1')
            ->assertInertia(fn ($page) => $page->where('rows.0.told', 1));
    }

    public function test_the_list_is_for_stock_staff_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/stock/requests')
            ->assertRedirect();
    }

    public function test_the_product_list_shows_how_many_are_waiting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();
        $this->ask($product, 'a@example.com');
        $this->ask($product, 'b@example.com');

        $this->actingAs($admin)
            ->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.id', $product->id)
                ->where('products.data.0.waiting_count', 2));
    }
}
