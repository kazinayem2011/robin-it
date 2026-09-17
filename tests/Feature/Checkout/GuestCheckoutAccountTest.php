<?php

namespace Tests\Feature\Checkout;

use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A guest's order joins an account, once the guest has proved the number.
 *
 * Checkout left nothing behind but a session id, so an order placed without
 * signing in could not be found from any other device, and the same person
 * ordering twice was two strangers. The number decides now — and because it
 * also signs the guest in, it is proved with a code rather than believed.
 */
class GuestCheckoutAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '01712345678';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);

        $this->canSendTexts(true);
    }

    private function canSendTexts(bool $on): void
    {
        config([
            'services.sms.enabled' => $on,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);
    }

    private function product(string $slug = 'ryzen-7'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Product '.$slug,
            'slug' => $slug,
            'price' => 30000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    /**
     * Something in a guest's cart, and the session that holds it kept for the
     * requests that follow — the test client would otherwise start a new
     * session, and a new session has an empty cart. JSON requests carry
     * cookies only when asked to.
     */
    private function guestCart(?Product $product = null): Product
    {
        $product ??= $this->product();

        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSuccessful();

        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());

        return $product;
    }

    private function askForCode(string $phone = self::PHONE): TestResponse
    {
        return $this->postJson('/otp/checkout', ['phone' => $phone]);
    }

    /** The code as the customer would read it off their phone. */
    private function codeFromTheText(): string
    {
        $sent = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? '')
            ->filter()
            ->last();

        $this->assertNotNull($sent, 'No text message was sent.');
        preg_match('/\b(\d{6})\b/', $sent, $m);
        $this->assertNotEmpty($m, "No six-digit code in: {$sent}");

        return $m[1];
    }

    private function checkout(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/checkout', array_merge([
            'name' => 'Karim Uddin',
            'phone' => self::PHONE,
            'address' => 'House 12, Road 4, Dhanmondi, Dhaka',
            'delivery_zone' => 'inside_dhaka',
            'payment' => 'cod',
        ], $overrides));
    }

    public function test_a_guest_is_asked_for_a_code(): void
    {
        $this->guestCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('data.errors.code.0', 'Enter the code we sent to your mobile.');

        $this->assertSame(0, Order::count());
    }

    public function test_a_new_number_gets_an_account_and_is_signed_in(): void
    {
        $this->guestCart();
        $this->askForCode()->assertSuccessful();

        $response = $this->checkout([
            'code' => $this->codeFromTheText(),
            'email' => 'Karim@Example.com',
        ])->assertCreated()->assertJsonPath('data.signed_in', true);

        $customer = User::where('phone', self::PHONE)->firstOrFail();

        $this->assertSame('Karim Uddin', $customer->name);
        $this->assertSame('karim@example.com', $customer->email);
        $this->assertSame(User::ROLE_CUSTOMER, $customer->role);
        $this->assertNotNull($customer->phone_verified_at);

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('karim@example.com', $order->shipping_address['email']);

        $this->assertAuthenticatedAs($customer);
    }

    /**
     * The order is what the guest saw on the page.
     *
     * Signing in merges the account's saved cart into the session's, so doing
     * it before the order would have sold the customer whatever they left in
     * that cart months ago, without it ever being on the checkout page.
     */
    public function test_a_returning_number_joins_its_account_without_its_saved_cart(): void
    {
        $customer = User::factory()->create([
            'phone' => self::PHONE,
            'email' => null,
            'name' => 'Karim From Before',
        ]);

        $saved = $this->product('saved-for-later');
        $accountCart = Cart::create(['user_id' => $customer->id]);
        CartItem::create(['cart_id' => $accountCart->id, 'product_id' => $saved->id, 'quantity' => 1]);

        $bought = $this->guestCart($this->product('bought-today'));
        $this->askForCode()->assertSuccessful();

        $response = $this->checkout([
            'code' => $this->codeFromTheText(),
            'email' => 'karim@example.com',
        ])->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame([$bought->id], $order->items->pluck('product_id')->all());
        $this->assertSame(1, User::count(), 'A second account was opened for the same number.');
        $this->assertAuthenticatedAs($customer);

        // Still waiting in the account's cart, untouched.
        $this->assertSame(
            [$saved->id],
            CartItem::where('cart_id', $accountCart->id)->pluck('product_id')->all()
        );

        $customer->refresh();
        $this->assertSame('Karim From Before', $customer->name, 'Checkout renamed the account.');
        $this->assertSame('karim@example.com', $customer->email, 'An account with no email should take the one given.');
        $this->assertNotNull($customer->phone_verified_at);
    }

    public function test_an_account_keeps_its_own_email(): void
    {
        $customer = User::factory()->create(['phone' => self::PHONE, 'email' => 'first@example.com']);

        $this->guestCart();
        $this->askForCode()->assertSuccessful();
        $this->checkout(['code' => $this->codeFromTheText(), 'email' => 'other@example.com'])->assertCreated();

        $this->assertSame('first@example.com', $customer->fresh()->email);
    }

    /** Somebody else's address is not claimed, and the order still goes through. */
    public function test_an_email_that_belongs_to_another_account_is_not_taken(): void
    {
        User::factory()->create(['phone' => '01811111111', 'email' => 'taken@example.com']);

        $this->guestCart();
        $this->askForCode()->assertSuccessful();
        $this->checkout(['code' => $this->codeFromTheText(), 'email' => 'taken@example.com'])->assertCreated();

        $this->assertNull(User::where('phone', self::PHONE)->firstOrFail()->email);
    }

    public function test_a_wrong_code_places_nothing_and_signs_in_nobody(): void
    {
        $this->guestCart();
        $this->askForCode()->assertSuccessful();

        $wrong = $this->codeFromTheText() === '000000' ? '111111' : '000000';

        $this->checkout(['code' => $wrong])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code', 'data.errors');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /** A text message must not open the admin panel. */
    public function test_a_staff_number_is_not_signed_in_by_a_code(): void
    {
        User::factory()->admin()->create(['phone' => self::PHONE]);

        $this->guestCart();
        $this->askForCode()->assertSuccessful();

        $this->checkout(['code' => $this->codeFromTheText()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This number belongs to a staff account. Please sign in with your password to place an order.');

        $this->assertGuest();
        $this->assertSame(0, Order::count());
    }

    public function test_a_suspended_account_is_not_signed_in_by_a_code(): void
    {
        User::factory()->create(['phone' => self::PHONE, 'is_active' => false]);

        $this->guestCart();
        $this->askForCode()->assertSuccessful();

        $this->checkout(['code' => $this->codeFromTheText()])->assertStatus(422);

        $this->assertGuest();
        $this->assertSame(0, Order::count());
    }

    /**
     * The code is spent once it is checked, so a verified guest whose order
     * then fails is signed in anyway — their next try needs no second code.
     */
    public function test_a_verified_guest_is_signed_in_even_when_the_order_fails(): void
    {
        $product = $this->guestCart();
        $this->askForCode()->assertSuccessful();

        // Gone between adding it and paying for it.
        $product->forceFill(['is_active' => false])->save();

        $this->checkout(['code' => $this->codeFromTheText()])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertAuthenticatedAs(User::where('phone', self::PHONE)->firstOrFail());
    }

    public function test_a_code_is_only_sent_for_a_cart_with_something_in_it(): void
    {
        $this->askForCode()
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone', 'data.errors');

        Http::assertNothingSent();
    }

    /**
     * With no gateway there is no code to send, and demanding one would stop
     * every guest from ordering. Checkout stays what it was.
     */
    public function test_without_texts_a_guest_orders_as_before(): void
    {
        $this->canSendTexts(false);
        $this->guestCart();

        $response = $this->checkout()->assertCreated()->assertJsonPath('data.signed_in', false);

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->assertNull($order->user_id);
        $this->assertSame(0, User::count());
        $this->assertGuest();

        $this->get('/checkout')->assertInertia(fn ($page) => $page->where('verifyPhone', false));
    }

    public function test_the_checkout_page_asks_a_guest_for_a_code_and_a_customer_for_none(): void
    {
        $this->get('/checkout')->assertInertia(fn ($page) => $page->where('verifyPhone', true));

        $customer = User::factory()->create(['phone' => self::PHONE, 'email' => 'karim@example.com']);

        $this->actingAs($customer)->get('/checkout')->assertInertia(fn ($page) => $page
            ->where('verifyPhone', false)
            ->where('contact.email', 'karim@example.com'));
    }

    /** Until now a guest's confirmation had nowhere to go but a text. */
    public function test_a_guests_confirmation_goes_to_the_email_they_gave(): void
    {
        Mail::fake();
        $this->canSendTexts(false);
        $this->guestCart();

        $this->checkout(['email' => 'karim@example.com'])->assertCreated();

        Mail::assertQueued(OrderConfirmationMail::class, fn ($mail) => $mail->hasTo('karim@example.com'));
    }
}
