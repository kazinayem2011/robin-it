<?php

namespace Tests\Feature\Checkout;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Signing in from checkout's "which account is this order for?" step.
 *
 * Choosing the account an email belongs to means its password, and the
 * sign-in page would have thrown away the delivery details just typed. This
 * signs the guest in where they are, with the sign-in form's own rules.
 */
class CheckoutSignInTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);
        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);

        $this->customer = User::factory()->create([
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
            'phone' => '01712345678',
            'password' => Hash::make('secret-pass-1'),
        ]);
    }

    private function product(string $slug): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id, 'name' => 'Product '.$slug, 'slug' => $slug,
            'price' => 30000, 'stock_quantity' => 10, 'is_active' => true,
        ]);
    }

    private function guestCart(Product $product): void
    {
        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSuccessful();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
    }

    private function signIn(string $login, string $password = 'secret-pass-1'): TestResponse
    {
        return $this->postJson('/checkout/sign-in', ['login' => $login, 'password' => $password]);
    }

    public function test_the_email_and_its_password_sign_the_guest_in_and_the_order_joins_that_account(): void
    {
        $this->guestCart($this->product('ryzen-7'));

        $this->signIn('karim@example.com')
            ->assertOk()
            ->assertJsonPath('data.email', 'karim@example.com')
            ->assertJsonPath('data.name', 'Karim Uddin');

        $this->assertAuthenticatedAs($this->customer);

        // Signed in now, so no code — and the cart came across with them.
        $response = $this->postJson('/api/checkout', [
            'name' => 'Karim Uddin',
            'phone' => '01812345678',
            'email' => 'karim@example.com',
            'address' => 'House 12, Road 4, Dhanmondi',
            'delivery_zone' => 'inside_dhaka',
        ])->assertCreated();

        $this->assertSame(
            $this->customer->id,
            Order::where('order_number', $response->json('data.order_number'))->value('user_id')
        );
    }

    public function test_the_mobile_and_its_password_work_too(): void
    {
        $this->signIn('01712345678')->assertOk();

        $this->assertAuthenticatedAs($this->customer);
    }

    /** What was in the guest's cart is on the account's cart afterwards. */
    public function test_the_guests_cart_is_carried_onto_the_account(): void
    {
        $saved = $this->product('saved-before');
        $accountCart = Cart::create(['user_id' => $this->customer->id]);
        CartItem::create(['cart_id' => $accountCart->id, 'product_id' => $saved->id, 'quantity' => 1]);

        $today = $this->product('picked-today');
        $this->guestCart($today);

        $this->signIn('karim@example.com')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$saved->id, $today->id],
            CartItem::where('cart_id', $accountCart->id)->pluck('product_id')->all()
        );
    }

    public function test_a_wrong_password_signs_nobody_in(): void
    {
        $this->signIn('karim@example.com', 'not-it')
            ->assertStatus(422)
            ->assertJsonPath('data.errors.login.0', 'Invalid email/mobile number or password.');

        $this->assertGuest();
    }

    public function test_a_suspended_account_cannot_sign_in_here_either(): void
    {
        $this->customer->forceFill(['is_active' => false])->save();

        $this->signIn('karim@example.com')->assertStatus(422);

        $this->assertGuest();
    }

    /** The sign-in form's attempt limit holds here as well. */
    public function test_guessing_is_limited(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->signIn('karim@example.com', 'wrong')->assertStatus(422);
        }

        $this->signIn('karim@example.com')->assertStatus(422);
        $this->assertGuest();
    }
}
