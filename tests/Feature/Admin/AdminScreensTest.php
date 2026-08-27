<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every admin screen, rendered.
 *
 * The eleven-domain AdminDashboardController was split into a controller per
 * section; these assert each screen still resolves, renders its own Inertia
 * component, and is still closed to customers.
 */
class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function screenProvider(): array
    {
        return [
            'dashboard' => ['/admin/dashboard', 'Admin/Dashboard'],
            'orders' => ['/admin/orders', 'Admin/Orders'],
            'products' => ['/admin/products', 'Admin/Products'],
            'categories' => ['/admin/categories', 'Admin/Categories'],
            'banners' => ['/admin/banners', 'Admin/Banners'],
            'coupons' => ['/admin/coupons', 'Admin/Coupons'],
            'stores' => ['/admin/stores', 'Admin/Stores'],
            'settings' => ['/admin/settings', 'Admin/Settings'],
            'customers' => ['/admin/customers', 'Admin/Customers'],
            'blogs' => ['/admin/blogs', 'Admin/Blogs'],
            'reviews' => ['/admin/reviews', 'Admin/Reviews'],
            'warranty' => ['/admin/warranty', 'Admin/Warranty'],
            'stock' => ['/admin/stock', 'Admin/Stock/Index'],
            'suppliers' => ['/admin/suppliers', 'Admin/Suppliers'],
        ];
    }

    #[DataProvider('screenProvider')]
    public function test_an_admin_can_open_every_screen(string $uri, string $component): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get($uri);

        $response->assertStatus(200);
        $this->assertSame($component, $response->viewData('page')['component']);
    }

    #[DataProvider('screenProvider')]
    public function test_a_customer_is_turned_away_from_every_screen(string $uri): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get($uri)->assertRedirect();
    }

    #[DataProvider('screenProvider')]
    public function test_a_guest_is_sent_to_sign_in(string $uri): void
    {
        $this->get($uri)->assertRedirect('/login');
    }

    public function test_the_bare_admin_url_lands_on_the_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/dashboard');
    }
}
