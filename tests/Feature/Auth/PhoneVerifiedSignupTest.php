<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signing up, and getting back in, with a code sent to a mobile.
 *
 * The service tests cover what a code is worth. These cover the two journeys
 * it exists for, and the things those journeys must not give away.
 */
class PhoneVerifiedSignupTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function lastCode(): string
    {
        $sent = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? '')
            ->filter()
            ->last();

        preg_match('/\b(\d{6})\b/', (string) $sent, $m);

        return $m[1] ?? '';
    }

    private function signUpDetails(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'phone' => '01712345678',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ], $overrides);
    }

    // --- signing up -------------------------------------------------------

    public function test_a_number_is_confirmed_before_the_account_exists(): void
    {
        $this->postJson(route('otp.register'), ['phone' => '01712345678'])
            ->assertOk();

        // Nothing yet: a code asked for is not an account.
        $this->assertDatabaseCount('users', 0);

        $this->post(route('register'), $this->signUpDetails(['code' => $this->lastCode()]))
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::firstWhere('phone', '01712345678');

        $this->assertNotNull($user);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_signing_up_without_the_code_is_refused(): void
    {
        $this->post(route('register'), $this->signUpDetails())
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_signing_up_with_the_wrong_code_is_refused(): void
    {
        $this->postJson(route('otp.register'), ['phone' => '01712345678'])->assertOk();
        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';

        $this->post(route('register'), $this->signUpDetails(['code' => $wrong]))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * A code confirms the number it was sent to and no other.
     *
     * Otherwise anybody could confirm their own number and then register
     * somebody else's, which is exactly what this is meant to stop.
     */
    public function test_a_code_for_one_number_does_not_confirm_another(): void
    {
        $this->postJson(route('otp.register'), ['phone' => '01712345678'])->assertOk();

        $this->post(route('register'), $this->signUpDetails([
            'phone' => '01812345678',
            'code' => $this->lastCode(),
        ]))->assertSessionHasErrors('code');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * A form the customer got wrong must not cost them their code.
     *
     * The code is spent when it is checked, so checking it before the password
     * rules would leave somebody who mistyped their password confirmation
     * waiting out the cooldown for a new one.
     */
    public function test_a_failed_password_rule_does_not_spend_the_code(): void
    {
        $this->postJson(route('otp.register'), ['phone' => '01712345678'])->assertOk();
        $code = $this->lastCode();

        $this->post(route('register'), $this->signUpDetails([
            'code' => $code,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertSessionHasErrors('password');

        $this->assertNull(OtpCode::first()->used_at);

        // And the same code still works.
        $this->post(route('register'), $this->signUpDetails(['code' => $code]))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_number_that_already_has_an_account_is_not_texted_a_sign_up_code(): void
    {
        User::factory()->create(['phone' => '01712345678']);

        // The app wraps validation errors in its own envelope rather than
        // Laravel's, so the assertion has to look where they actually land.
        $this->postJson(route('otp.register'), ['phone' => '01712345678'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'data.errors.phone.0',
                'This mobile number is already registered. Sign in instead.'
            );

        $this->assertDatabaseCount('otp_codes', 0);
        Http::assertNothingSent();
    }

    /**
     * With no gateway, sign-up works as it did before this existed.
     *
     * A code cannot be sent, so demanding one would shut every new customer out
     * of the shop — which is a worse outcome than an unverified number.
     */
    public function test_sign_up_still_works_with_no_sms_gateway(): void
    {
        config(['services.sms.enabled' => false]);

        $this->post(route('register'), $this->signUpDetails())
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::firstWhere('phone', '01712345678');

        $this->assertNotNull($user);
        // Not claimed, because nothing proved it.
        $this->assertNull($user->phone_verified_at);
    }

    // --- getting back in --------------------------------------------------

    public function test_a_forgotten_password_can_be_reset_by_text(): void
    {
        $user = User::factory()->create([
            'phone' => '01712345678',
            'password' => Hash::make('old-password'),
        ]);
        $was = $user->remember_token;

        $this->postJson(route('otp.password'), ['phone' => '01712345678'])->assertOk();

        $this->post(route('password.phone.store'), [
            'phone' => '01712345678',
            'code' => $this->lastCode(),
            'password' => 'Br4nd-New-Passw0rd!',
            'password_confirmation' => 'Br4nd-New-Passw0rd!',
        ])->assertRedirect();

        $user->refresh();

        $this->assertTrue(Hash::check('Br4nd-New-Passw0rd!', $user->password));
        $this->assertAuthenticatedAs($user);

        // Reading a code sent to the number is the same proof sign-up asks for.
        $this->assertNotNull($user->phone_verified_at);

        // Any browser left signed in with "remember me" is cut loose: a reset
        // is often exactly the moment one of them is not the customer's.
        $this->assertNotSame($was, $user->remember_token);
    }

    /**
     * The reply must be the same whether or not the number shops here.
     *
     * A different answer turns this endpoint into a way to ask "is this person
     * a customer", which is a list worth stealing.
     */
    public function test_a_reset_request_says_the_same_thing_for_a_number_with_no_account(): void
    {
        User::factory()->create(['phone' => '01712345678']);

        $known = $this->postJson(route('otp.password'), ['phone' => '01712345678']);
        $unknown = $this->postJson(route('otp.password'), ['phone' => '01912345678']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->status(), $unknown->status());
    }

    /**
     * And no text is sent for one, so the endpoint cannot be used to spend the
     * shop's credit on any number in the country.
     */
    public function test_no_text_is_sent_for_a_number_with_no_account(): void
    {
        $this->postJson(route('otp.password'), ['phone' => '01912345678'])->assertOk();

        $this->assertDatabaseCount('otp_codes', 0);
        Http::assertNothingSent();
    }

    public function test_a_reset_with_the_wrong_code_is_refused(): void
    {
        $user = User::factory()->create([
            'phone' => '01712345678',
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson(route('otp.password'), ['phone' => '01712345678'])->assertOk();
        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';

        $this->post(route('password.phone.store'), [
            'phone' => '01712345678',
            'code' => $wrong,
            'password' => 'Br4nd-New-Passw0rd!',
            'password_confirmation' => 'Br4nd-New-Passw0rd!',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
        $this->assertGuest();
    }

    /**
     * A number with no account gets the same error as a wrong code, so the
     * reset form cannot answer the question the request endpoint refuses to.
     */
    public function test_resetting_an_account_that_does_not_exist_gives_nothing_away(): void
    {
        User::factory()->create(['phone' => '01712345678']);
        $this->postJson(route('otp.password'), ['phone' => '01712345678'])->assertOk();
        $code = $this->lastCode();

        $mine = $this->post(route('password.phone.store'), [
            'phone' => '01712345678', 'code' => '000000',
            'password' => 'Br4nd-New-Passw0rd!', 'password_confirmation' => 'Br4nd-New-Passw0rd!',
        ]);

        $theirs = $this->post(route('password.phone.store'), [
            'phone' => '01912345678', 'code' => $code,
            'password' => 'Br4nd-New-Passw0rd!', 'password_confirmation' => 'Br4nd-New-Passw0rd!',
        ]);

        $mine->assertSessionHasErrors('code');
        $theirs->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /** The reset page has to know how long the resend button stays disabled. */
    public function test_the_reset_page_is_told_the_cooldown(): void
    {
        $this->get(route('password.phone'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/ForgotPasswordPhone')
                ->where('resendSeconds', OtpService::RESEND_SECONDS));
    }

    /** And the sign-up page has to know whether to ask for a code at all. */
    public function test_the_sign_up_page_is_told_whether_a_code_is_needed(): void
    {
        $this->get(route('register'))
            ->assertInertia(fn ($page) => $page->where('verifyPhone', true));

        config(['services.sms.enabled' => false]);

        $this->get(route('register'))
            ->assertInertia(fn ($page) => $page->where('verifyPhone', false));
    }
}
