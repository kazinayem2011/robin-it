<?php

namespace Tests\Feature\Checkout;

use App\Mail\WelcomeCustomerMail;
use App\Models\Category;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\CheckoutAccount;
use App\Services\SmsService;
use App\Support\SmsTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
 * The text and the email carry the password generated for the account: the
 * shop's decision is that customers always sign in with a password, and an
 * account checkout made used to have none, so once the session ended there was
 * no way back into it. The confirmation page says where the password went, and
 * never shows it.
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

        $account = $this->accountText();
        $this->assertNotNull($account, 'No account text was sent: '.implode(' | ', $this->textsSent()));

        /*
         * With the password generated for the account — customers always sign
         * in with one — and no more than two parts. The same password is in
         * the email, and it is the one on the account.
         */
        $password = $this->passwordIn($account);
        $this->assertLessThanOrEqual(2, SmsService::parts($account));
        $this->assertTrue(Hash::check($password, $customer->password));

        Mail::assertQueued(WelcomeCustomerMail::class, fn ($mail) => $mail->password === $password);
    }

    /**
     * Says what to sign in with and that it can be changed: this number and
     * this password; change it in the profile.
     */
    public function test_the_text_says_how_to_sign_in_with_the_password(): void
    {
        $this->orderAsNewCustomer();

        $account = $this->accountText();

        $this->assertStringContainsString('এই নম্বর ও পাসওয়ার্ড', $account);
        $this->assertStringContainsString('লগইন', $account);
        $this->assertStringContainsString('প্রোফাইলে বদলে নিন', $account);
        $this->assertStringStartsWith('(', $account);
    }

    /** A customer whose account has a password is not welcomed again, or sent one. */
    public function test_a_returning_customer_with_a_password_is_not_told_about_an_account_they_have(): void
    {
        $customer = User::factory()->create(['phone' => self::PHONE, 'email' => null]);
        $before = $customer->password;

        $this->guestCart();
        $this->postJson('/checkout/sign-in', ['login' => self::PHONE, 'password' => 'password'])->assertOk();
        $this->checkout()->assertCreated();

        Mail::assertNotQueued(WelcomeCustomerMail::class);
        $this->assertNull($this->accountText());
        $this->assertSame($before, $customer->fresh()->password);
    }

    /**
     * An account checkout made before passwords were generated has none; the
     * next checkout that proves its number gives it one, the same way.
     */
    public function test_a_returning_customer_with_no_password_is_sent_one(): void
    {
        $customer = User::factory()->create(['phone' => self::PHONE, 'email' => 'karim@example.com', 'password' => null]);

        $this->orderAsNewCustomer();

        $password = $this->passwordIn($this->accountText());
        $this->assertTrue(Hash::check($password, $customer->fresh()->password));
        Mail::assertQueued(WelcomeCustomerMail::class, fn ($mail) => $mail->password === $password);
    }

    public function test_the_text_can_be_switched_off_without_losing_the_email(): void
    {
        SiteSetting::set('sms_on_account_created', '0', 'sms');

        $this->orderAsNewCustomer(['email' => 'karim@example.com']);

        $this->assertNull($this->accountText());
        Mail::assertQueued(WelcomeCustomerMail::class);
    }

    /**
     * Switched off, the text still goes to a customer who gave no email: it is
     * the only way their password reaches them.
     */
    public function test_with_no_email_the_text_goes_even_when_switched_off(): void
    {
        SiteSetting::set('sms_on_account_created', '0', 'sms');

        $this->orderAsNewCustomer();

        $this->assertNotNull($this->accountText());
    }

    /** The password is in the messages, and never in what the page is sent. */
    public function test_the_password_is_never_returned_to_the_page(): void
    {
        $this->guestCart();
        $this->postJson('/otp/checkout', ['phone' => self::PHONE])->assertSuccessful();
        $response = $this->checkout(['code' => $this->codeFromTheText()])->assertCreated();

        $password = $this->passwordIn($this->accountText());
        $this->assertStringNotContainsString($password, $response->getContent());

        $order = Order::latest('id')->firstOrFail();
        $page = $this->get('/order/success?order='.$order->order_number);
        $this->assertStringNotContainsString($password, $page->getContent());
    }

    /**
     * The confirmation page, which is where they are already looking: shown
     * for the order that made the account, in the session that placed it.
     */
    public function test_the_confirmation_page_says_an_account_was_made(): void
    {
        $this->orderAsNewCustomer();

        $order = Order::latest('id')->firstOrFail();

        $this->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('accountIsNew', true));

        // Not for the same customer's next order.
        $customer = User::where('phone', self::PHONE)->firstOrFail();
        $later = Order::create([
            'order_number' => 'ORD-LATER00001', 'user_id' => $customer->id,
            'status' => 'pending', 'subtotal' => 100, 'shipping_fee' => 0, 'discount' => 0, 'total' => 100,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Karim Uddin', 'phone' => self::PHONE],
        ]);

        $this->get('/order/success?order='.$later->order_number)
            ->assertInertia(fn ($page) => $page->where('accountIsNew', false));
    }

    private function accountText(): ?string
    {
        return collect($this->textsSent())->first(fn ($text) => str_contains($text, 'অ্যাকাউন্ট'));
    }

    private function passwordIn(?string $text): string
    {
        $this->assertNotNull($text, 'No account-created text was sent.');
        preg_match('/পাসওয়ার্ড\s+([A-Za-z0-9]{8})\b/u', $text, $m);
        $this->assertNotEmpty($m, "No password in: {$text}");

        return $m[1];
    }

    /** The welcome email for an account checkout made shows how to sign in. */
    public function test_the_welcome_email_shows_the_sign_in_details(): void
    {
        $customer = User::factory()->create(['phone' => self::PHONE, 'email' => 'karim@example.com']);

        $html = (new WelcomeCustomerMail($customer, 'x7Kp4mQa'))->render();

        $this->assertStringContainsString('Your sign-in details', $html);
        $this->assertStringContainsString(self::PHONE, $html);
        $this->assertStringContainsString('karim@example.com', $html);
        $this->assertStringContainsString('x7Kp4mQa', $html);
        $this->assertStringContainsString('change it from your profile', $html);
    }

    /** Somebody who registered chose their own password; nothing about one is sent. */
    public function test_the_welcome_email_for_a_registration_has_no_password(): void
    {
        $customer = User::factory()->create(['phone' => self::PHONE, 'email' => 'karim@example.com']);

        $html = (new WelcomeCustomerMail($customer))->render();

        $this->assertStringNotContainsString('Your sign-in details', $html);
        $this->assertStringNotContainsString('Password:', $html);
    }

    /** A shop's own welcome wording without {sign_in_details} still carries them. */
    public function test_a_shops_own_welcome_wording_still_carries_the_password(): void
    {
        EmailTemplate::create([
            'key' => 'welcome', 'name' => 'Welcome', 'group' => 'Account',
            'subject' => 'Hello from {shop_name}',
            'body' => '<p>Hi {customer_name}, welcome aboard.</p>',
            'variables' => ['shop_name', 'customer_name', 'shop_url'],
        ]);

        $customer = User::factory()->create(['phone' => self::PHONE]);

        $this->assertStringContainsString('x7Kp4mQa', (new WelcomeCustomerMail($customer, 'x7Kp4mQa'))->render());
        $this->assertStringNotContainsString('Password:', (new WelcomeCustomerMail($customer))->render());
    }

    /** And a shop's own SMS wording without {password} still carries it. */
    public function test_a_shops_own_text_wording_still_carries_the_password(): void
    {
        SmsTemplate::create([
            'key' => 'account_created', 'name' => 'Account created', 'group' => 'Account',
            'body' => '({shop_name}) আপনার অ্যাকাউন্ট তৈরি হয়েছে।',
            'variables' => ['shop_name'],
        ]);

        $text = SmsTemplates::accountCreated('Robins Computer', 'x7Kp4mQa');

        $this->assertStringContainsString('আপনার অ্যাকাউন্ট', $text);
        $this->assertStringContainsString('x7Kp4mQa', $text);
        $this->assertLessThanOrEqual(2, SmsService::parts($text));
    }

    /** Readable, and drawn from letters and digits that cannot be misread. */
    public function test_a_generated_password_avoids_look_alike_characters(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $password = CheckoutAccount::generatePassword();

            $this->assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9]{8}$/', $password);
            $this->assertMatchesRegularExpression('/\d/', $password);
            $this->assertMatchesRegularExpression('/[a-zA-Z]/', $password);
        }
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
