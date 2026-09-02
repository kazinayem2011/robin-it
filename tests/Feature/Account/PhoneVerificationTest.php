<?php

namespace Tests\Feature\Account;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirming a mobile number on an account that already exists.
 *
 * phone_verified_at was set in exactly two places — registering by mobile, and
 * resetting a password by mobile — so a customer who signed up with an email
 * and added a number afterwards had a number on their account that nothing
 * could ever confirm.
 */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A code can only be sent if the gateway is on; without this the send
        // endpoint correctly refuses and every test here fails for the wrong
        // reason.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);

        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);
    }

    private function customerWithPhone(string $phone = '01711111111'): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'phone' => $phone,
            'phone_verified_at' => null,
        ]);
    }

    /** The code goes to the number on the account, and to nothing else. */
    public function test_it_sends_a_code_to_the_number_on_the_account(): void
    {
        $user = $this->customerWithPhone();

        $this->actingAs($user)
            ->postJson(route('phone.verification.send'))
            ->assertSuccessful();

        $this->assertDatabaseHas('otp_codes', [
            'phone' => $user->phone,
            'purpose' => OtpCode::PURPOSE_VERIFY_PHONE,
        ]);
    }

    /**
     * The number is read off the account, never off the request, so a code
     * cannot be aimed at somebody else's handset.
     */
    public function test_a_number_in_the_request_is_ignored(): void
    {
        $user = $this->customerWithPhone('01711111111');

        $this->actingAs($user)
            ->postJson(route('phone.verification.send'), ['phone' => '01999999999'])
            ->assertSuccessful();

        $this->assertDatabaseHas('otp_codes', ['phone' => '01711111111']);
        $this->assertDatabaseMissing('otp_codes', ['phone' => '01999999999']);
    }

    public function test_the_right_code_confirms_the_number(): void
    {
        $user = $this->customerWithPhone();

        $code = $this->issueKnownCode($user->phone);

        $this->actingAs($user)
            ->postJson(route('phone.verification.verify'), ['code' => $code])
            ->assertSuccessful();

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_a_wrong_code_confirms_nothing(): void
    {
        $user = $this->customerWithPhone();

        $this->issueKnownCode($user->phone);

        $this->actingAs($user)
            ->postJson(route('phone.verification.verify'), ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->phone_verified_at);
    }

    /**
     * A code issued for signing in is not a code for confirming a number.
     * Sharing one pool would let a password-reset code, which arrives for a
     * different reason, be spent here.
     */
    public function test_a_code_for_another_purpose_is_refused(): void
    {
        $user = $this->customerWithPhone();

        $code = $this->issueKnownCode($user->phone, OtpCode::PURPOSE_PASSWORD_RESET);

        $this->actingAs($user)
            ->postJson(route('phone.verification.verify'), ['code' => $code])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_an_account_with_no_number_is_told_to_add_one(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'phone' => null]);

        $this->actingAs($user)
            ->postJson(route('phone.verification.send'))
            ->assertStatus(422);
    }

    public function test_a_guest_cannot_use_either_endpoint(): void
    {
        $this->postJson(route('phone.verification.send'))->assertStatus(401);
        $this->postJson(route('phone.verification.verify'), ['code' => '123456'])->assertStatus(401);
    }

    /** Confirming twice does not move the date it was first confirmed. */
    public function test_the_confirmed_date_is_not_re_stamped(): void
    {
        $user = $this->customerWithPhone();

        $this->actingAs($user)
            ->postJson(route('phone.verification.verify'), ['code' => $this->issueKnownCode($user->phone)])
            ->assertSuccessful();

        $first = $user->fresh()->phone_verified_at;

        $this->travel(2)->minutes();

        $this->actingAs($user)
            ->postJson(route('phone.verification.verify'), ['code' => $this->issueKnownCode($user->phone)])
            ->assertSuccessful();

        $this->assertEquals($first, $user->fresh()->phone_verified_at);
    }

    /**
     * Writes the row the service would write, with a code this test knows.
     * Going through issue() would send it by SMS and never hand it back.
     */
    private function issueKnownCode(string $phone, string $purpose = OtpCode::PURPOSE_VERIFY_PHONE): string
    {
        $code = '424242';

        OtpCode::for($phone, $purpose)->live()->update(['used_at' => now()]);

        OtpCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(OtpService::TTL_SECONDS),
            'attempts' => 0,
            'ip' => '127.0.0.1',
        ]);

        return $code;
    }
}
