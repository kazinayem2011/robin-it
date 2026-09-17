<?php

namespace Tests\Feature\Checkout;

use App\Mail\WelcomeCustomerMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Somebody who checks out as a guest is given an account, and told so.
 *
 * They came to buy something, not to register, so nothing said an account now
 * exists — they would have found out by coming back to the shop one day. Three
 * places say it now: the confirmation page they are already reading, a text,
 * and the shop's own welcome email, which until now was a template that
 * reached nobody at all.
 *
 * None of them carries a password. The account has none until the customer
 * chooses one, and a password sent by text or email is a password left in an
 * inbox and in a gateway's logs.
 */
class NewAccountIsAnnouncedTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '01712345678';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);
        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);
    }

    private function guestCart(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Ryzen 7', 'slug' => 'ryzen-7-welcome',
            'price' => 30000, 'stock_quantity' => 10, 'is_active' => true,
        ]);

        $this->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertSuccessful();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());

        return $product;
    }

    private function codeFromTheText(): string
    {
        $sent = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? '')
            ->filter()
            ->last();

        preg_match('/\b(\d{6})\b/', (string) $sent, $m);

        return $m[1] ?? '000000';
    }

    /** @return list<string> */
    private function textsSent(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function checkout(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/checkout', array_merge([
            'name' => 'Karim Uddin',
            'phone' => self::PHONE,
            'address' => 'House 12, Road 4, Dhanmondi, Dhaka',
            'delivery_zone' => 'inside_dhaka',
        ], $overrides));
    }

    private function orderAsNewCustomer(array $overrides = []): void
    {
        $this->guestCart();
        $this->postJson('/otp/checkout', ['phone' => self::PHONE] + $overrides)->assertSuccessful();
        $this->checkout($overrides + ['code' => $this->codeFromTheText()])->assertCreated();
    }

    public function test_a_new_account_is_told_by_text_and_by_email(): void
    {
        $this->orderAsNewCustomer(['email' => 'karim@example.com']);

        $customer = User::where('phone', self::PHONE)->firstOrFail();

        Mail::assertQueued(WelcomeCustomerMail::class, fn ($mail) => $mail->hasTo('karim@example.com'));

        $account = collect($this->textsSent())->first(fn ($text) => str_contains($text, 'অ্যাকাউন্ট'));
        $this->assertNotNull($account, 'No account text was sent: '.implode(' | ', $this->textsSent()));

        // One part, and nothing in it that could be a password — there is none.
        $this->assertSame(1, SmsService::parts($account));
        $this->assertFalse($customer->hasPassword());
    }

    public function test_the_text_says_where_to_set_a_password_rather_than_carrying_one(): void
    {
        $this->orderAsNewCustomer();

        $account = collect($this->textsSent())->first(fn ($text) => str_contains($text, 'অ্যাকাউন্ট'));

        $this->assertStringContainsString('পাসওয়ার্ড', $account);
        // Six digits in this message would be a code; anything longer, a password.
        $this->assertDoesNotMatchRegularExpression('/\d{4,}/', $account);
    }

    /** A customer who already has an account is not welcomed again. */
    public function test_a_returning_customer_is_not_told_about_an_account_they_have(): void
    {
        User::factory()->create(['phone' => self::PHONE, 'email' => null, 'password' => null]);

        $this->orderAsNewCustomer();

        Mail::assertNotQueued(WelcomeCustomerMail::class);
        $this->assertNull(
            collect($this->textsSent())->first(fn ($text) => str_contains($text, 'অ্যাকাউন্ট'))
        );
    }

    public function test_the_text_can_be_switched_off_without_losing_the_email(): void
    {
        SiteSetting::set('sms_on_account_created', '0', 'sms');

        $this->orderAsNewCustomer(['email' => 'karim@example.com']);

        $this->assertNull(
            collect($this->textsSent())->first(fn ($text) => str_contains($text, 'অ্যাকাউন্ট'))
        );
        Mail::assertQueued(WelcomeCustomerMail::class);
    }

    /**
     * The confirmation page, which is where they are already looking.
     *
     * Shown while the account has no password, which is the account checkout
     * makes — and gone once they have set one.
     */
    public function test_the_confirmation_page_says_an_account_was_made(): void
    {
        $this->orderAsNewCustomer();

        $order = Order::latest('id')->firstOrFail();
        $customer = User::where('phone', self::PHONE)->firstOrFail();

        $this->actingAs($customer)
            ->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('accountIsNew', true));

        $customer->forceFill(['password' => bcrypt('chosen-one')])->save();

        $this->actingAs($customer)
            ->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('accountIsNew', false));
    }

    public function test_somebody_elses_confirmation_page_says_nothing(): void
    {
        $this->orderAsNewCustomer();
        $order = Order::latest('id')->firstOrFail();

        $this->actingAs(User::factory()->create(['password' => null]))
            ->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('accountIsNew', false));
    }

    /** The welcome template was written, editable, and sent from nowhere. */
    public function test_registering_sends_the_welcome_email_at_last(): void
    {
        config(['services.sms.enabled' => false]);

        $this->post('/register', [
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'phone' => '01811111111',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect();

        Mail::assertQueued(WelcomeCustomerMail::class, fn ($mail) => $mail->hasTo('rahim@example.com'));
    }

    public function test_registering_with_only_a_mobile_sends_no_welcome_email(): void
    {
        config(['services.sms.enabled' => false]);

        $this->post('/register', [
            'name' => 'Rahim Uddin',
            'phone' => '01811111111',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect();

        Mail::assertNotQueued(WelcomeCustomerMail::class);
    }
}
