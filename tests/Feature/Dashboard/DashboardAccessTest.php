<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_customer_dashboard(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_customer_can_access_customer_dashboard(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $response = $this->actingAs($customer)->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_customer_cannot_access_admin_dashboard(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $response = $this->actingAs($customer)->get('/admin/dashboard');
        $response->assertRedirect('/dashboard');
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_admin_orders_and_products(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $responseOrders = $this->actingAs($admin)->get('/admin/orders');
        $responseOrders->assertStatus(200);

        $responseProducts = $this->actingAs($admin)->get('/admin/products');
        $responseProducts->assertStatus(200);
    }
}
