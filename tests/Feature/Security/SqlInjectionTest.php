<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every value that reaches a query is a bound parameter, and no request input
 * is ever used as a column, table or direction. These pin that down at the
 * endpoints an attacker can actually reach.
 */
class SqlInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function catalogue(): void
    {
        $category = Category::create(['name' => 'Processors', 'slug' => 'cpu', 'is_active' => true]);

        foreach (['Ryzen 5 7600', 'Core i5 14600K', '100% Copper Cooler'] as $i => $name) {
            Product::create([
                'category_id' => $category->id,
                'name' => $name,
                'slug' => 'product-'.$i,
                'price' => 20000,
                'stock_quantity' => 5,
                'is_active' => true,
            ]);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function payloadProvider(): array
    {
        return [
            'tautology' => ["' OR '1'='1"],
            'comment terminator' => ["' OR 1=1--"],
            'stacked drop' => ["'; DROP TABLE products;--"],
            'union select' => ["' UNION SELECT username, password FROM users--"],
            'quote escape' => ['" OR ""="'],
            'time based' => ["%' AND SLEEP(5)-- "],
            'backslash' => ['\\'],
            'null byte' => ["a\0b"],
        ];
    }

    #[DataProvider('payloadProvider')]
    public function test_a_search_payload_is_treated_as_text(string $payload): void
    {
        $this->catalogue();

        $response = $this->getJson('/api/products?search='.urlencode($payload));

        $response->assertStatus(200);
        // Matched as literal text, so it finds nothing — never the whole table.
        $this->assertSame([], $response->json('data'));
        $this->assertTrue(Schema::hasTable('products'), 'The products table is gone.');
        $this->assertSame(3, Product::count());
    }

    #[DataProvider('payloadProvider')]
    public function test_a_payload_in_a_filter_cannot_widen_the_result_set(string $payload): void
    {
        $this->catalogue();

        $this->getJson('/api/products?category_slug='.urlencode($payload))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /** Sorting is a fixed whitelist — it never becomes an identifier. */
    #[DataProvider('payloadProvider')]
    public function test_a_payload_in_the_sort_is_refused(string $payload): void
    {
        $this->catalogue();

        $this->getJson('/api/products?sort='.urlencode($payload))->assertStatus(422);
    }

    /**
     * LIKE wildcards are escaped, so a search box cannot be used to force a full
     * table scan — or to quietly match rows the search text does not name.
     */
    public function test_like_wildcards_are_escaped_in_the_storefront_search(): void
    {
        $this->catalogue();

        // The property that matters, and it holds on every driver: a search made
        // of wildcards cannot return the catalogue, and cannot force a scan.
        $this->getJson('/api/products?search=%25')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson('/api/products?search=_')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson('/api/products?search='.urlencode('%_%'))->assertStatus(200)->assertJsonCount(0, 'data');
    }

    /** The admin screens escape them too; they used not to. */
    public function test_like_wildcards_are_escaped_in_the_admin_searches(): void
    {
        $this->catalogue();
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['%25', '_', urlencode('%_%')] as $wildcard) {
            $props = $this->actingAs($admin)->get("/admin/products?search={$wildcard}")
                ->assertStatus(200)
                ->viewData('page')['props'];

            $this->assertCount(
                0,
                $props['products']['data'],
                "A bare wildcard ({$wildcard}) matched the whole catalogue."
            );
        }
    }

    public function test_a_payload_in_a_customer_search_finds_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create(['role' => 'customer']);

        $props = $this->actingAs($admin)->get('/admin/customers?search='.urlencode("' OR 1=1--"))
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertCount(0, $props['customers']['data']);
    }

    /**
     * Route keys that controllers type as `int` are constrained to digits.
     *
     * Without the constraint Laravel handed the raw segment to a method typed
     * `int $orderId`, PHP raised a TypeError, and the request came back a 500 —
     * which with APP_DEBUG on printed the absolute path of the file. Nothing
     * reached the database either way, but "not found" is the honest answer.
     */
    #[DataProvider('payloadProvider')]
    public function test_a_non_numeric_id_is_not_found_rather_than_a_server_error(string $payload): void
    {
        $encoded = urlencode($payload);

        $this->getJson("/api/products/{$encoded}/branches")->assertStatus(404);
        $this->get("/orders/{$encoded}/invoice")->assertStatus(404);
    }

    /** A real id still routes. */
    public function test_a_numeric_id_still_reaches_its_controller(): void
    {
        $this->catalogue();

        $this->getJson('/api/products/'.Product::first()->id.'/branches')->assertStatus(200);
    }

    /** Order tracking takes both of its inputs as bound values. */
    public function test_order_tracking_cannot_be_subverted(): void
    {
        $user = User::factory()->create();
        Order::create([
            'order_number' => 'ORD-REALORDER',
            'user_id' => $user->id,
            'subtotal' => 100, 'shipping_fee' => 60, 'discount' => 0, 'total' => 160,
            'status' => 'pending', 'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678'],
        ]);

        $this->postJson('/api/orders/track', [
            'order_number' => "' OR 1=1--",
            'phone' => '01712345678',
        ])->assertStatus(404);

        $this->assertSame(1, Order::count());
    }
}
