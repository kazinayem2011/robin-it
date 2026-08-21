<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_email(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@robinit.com',
            'phone' => '01722000000',
            'password' => Hash::make('password123'),
            'role' => 'customer',
        ]);

        $response = $this->post('/login', [
            'login' => 'customer@robinit.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_using_bangladeshi_mobile_number(): void
    {
        $user = User::factory()->create([
            'email' => 'customer_bd@robinit.com',
            'phone' => '01722000000',
            'password' => Hash::make('password123'),
            'role' => 'customer',
        ]);

        // Test with raw 11-digit phone
        $response = $this->post('/login', [
            'login' => '01722000000',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_using_phone_with_plus_88_prefix(): void
    {
        $user = User::factory()->create([
            'email' => 'customer_prefix@robinit.com',
            'phone' => '01722000000',
            'password' => Hash::make('password123'),
            'role' => 'customer',
        ]);

        // Test with +880 prefix
        $response = $this->post('/login', [
            'login' => '+8801722000000',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_admin_user_is_redirected_to_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@robinit.com',
            'phone' => '01711000000',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $response = $this->post('/login', [
            'login' => 'admin@robinit.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->post('/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
