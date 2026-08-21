<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_with_bangladeshi_mobile_number(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '01711223344',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'phone' => '01711223344',
            'role' => 'customer',
        ]);
    }
}
