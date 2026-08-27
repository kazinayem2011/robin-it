<?php

namespace Tests\Feature\Admin;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The suite previously only checked that an admin could *read* the dashboard.
 * Nothing asserted that a customer is blocked from the write endpoints.
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function adminWriteRouteProvider(): array
    {
        return [
            'create product' => ['postJson', '/api/admin/products'],
            'update product' => ['patchJson', '/api/admin/products/1'],
            'create category' => ['postJson', '/api/admin/categories'],
            'update category' => ['patchJson', '/api/admin/categories/1'],
            'delete category' => ['deleteJson', '/api/admin/categories/1'],
            'update order status' => ['patchJson', '/api/admin/orders/1/status'],
            'create banner' => ['postJson', '/api/admin/banners'],
            'delete banner' => ['deleteJson', '/api/admin/banners/1'],
            'create coupon' => ['postJson', '/api/admin/coupons'],
            'delete coupon' => ['deleteJson', '/api/admin/coupons/1'],
            'create blog' => ['postJson', '/api/admin/blogs'],
            'delete blog' => ['deleteJson', '/api/admin/blogs/1'],
            'update settings' => ['postJson', '/api/admin/settings'],
            'create store' => ['postJson', '/api/admin/stores'],
        ];
    }

    #[DataProvider('adminWriteRouteProvider')]
    public function test_a_customer_cannot_reach_admin_write_endpoints(string $verb, string $uri): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this->actingAs($customer)->{$verb}($uri, []);

        $this->assertContains(
            $response->status(),
            [401, 403],
            "{$verb} {$uri} must not be reachable by a customer (got {$response->status()})."
        );

        $this->assertFalse(
            $response->isSuccessful(),
            "{$verb} {$uri} returned a success status to a customer."
        );
    }

    #[DataProvider('adminWriteRouteProvider')]
    public function test_a_guest_cannot_reach_admin_write_endpoints(string $verb, string $uri): void
    {
        $response = $this->{$verb}($uri, []);

        $this->assertFalse(
            $response->isSuccessful(),
            "{$verb} {$uri} returned a success status to a guest."
        );
    }

    public function test_a_customer_is_redirected_away_from_the_admin_dashboard(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get('/admin/dashboard')->assertRedirect();
    }

    public function test_role_cannot_be_escalated_through_profile_update(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->post('/'.ApiEndpoints::ACCOUNT_PROFILE, [
            'name' => 'Sneaky Customer',
            'email' => 'sneaky@example.com',
            'phone' => '01712345678',
            'role' => 'admin',
        ]);

        $this->assertSame('customer', $customer->fresh()->role);
    }

    public function test_role_cannot_be_escalated_at_registration(): void
    {
        $this->post('/register', [
            'name' => 'New Customer',
            'email' => 'new@example.com',
            'phone' => '01812345678',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
        ]);

        $this->assertSame('customer', User::where('email', 'new@example.com')->first()->role);
    }

    public function test_an_admin_can_perform_a_write(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Admin Created CPU',
            'price' => 25000,
            'stock_quantity' => 5,
        ])->assertStatus(201);

        $this->assertDatabaseHas('products', ['name' => 'Admin Created CPU']);
    }
}
