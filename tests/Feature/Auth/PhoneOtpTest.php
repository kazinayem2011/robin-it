<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Proving a mobile number belongs to whoever typed it.
 *
 * Sign-up asked for a number and believed it, so an account could be opened on
 * somebody else's — and that number is where the confirmation, the dispatch
 * note and the delivery rider all end up.
 *
 * Password reset went by email only, which for a customer who signed up with an
 * address they never open meant a forgotten password was a lost account.
 *
 * Almost everything here is a limit rather than a feature. Six digits are cheap
 * to guess and every code costs the shop a text.
 */
class PhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $otp;

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

        $this->otp = app(OtpService::class);
    }

    /** The code as the customer would read it off their phone. */
    private function codeFromTheTextMessage(): string
    {
        $sent = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? '')
            ->filter()
            ->last();

        $this->assertNotNull($sent, 'No text message was sent.');
        preg_match('/\b(\d{6})\b/', $sent, $m);
        $this->assertNotEmpty($m, "No six-digit code in the message: {$sent}");

        return $m[1];
    }

    // --- the code itself -------------------------------------------------

    public function test_a_code_is_six_digits(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->codeFromTheTextMessage());
    }

    /**
     * A database that leaks should not hand over accounts as well.
     */
    public function test_the_code_is_never_stored_as_it_was_sent(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $code = $this->codeFromTheTextMessage();

        $row = OtpCode::first();

        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertArrayNotHasKey('code_hash', $row->toArray());
    }

    public function test_the_right_code_is_accepted(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);

        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $this->codeFromTheTextMessage());

        $this->assertNotNull(OtpCode::first()->used_at);
    }

    /**
     * The number is normalised on the way in and on the way out, so a code
     * asked for as "+880 1712-345678" is the same code as one typed plainly.
     */
    public function test_the_same_number_written_differently_is_the_same_code(): void
    {
        $this->otp->issue('+880 1712-345678', OtpCode::PURPOSE_REGISTER);

        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $this->codeFromTheTextMessage());

        $this->assertSame(1, OtpCode::count());
        $this->assertSame('01712345678', OtpCode::first()->phone);
    }

    // --- what must not work ----------------------------------------------

    public function test_a_wrong_code_is_refused(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $real = $this->codeFromTheTextMessage();
        $wrong = $real === '000000' ? '111111' : '000000';

        $this->expectException(ValidationException::class);

        try {
            $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $wrong);
        } finally {
            // Counted, so the cap can bite.
            $this->assertSame(1, OtpCode::first()->attempts);
            $this->assertNull(OtpCode::first()->used_at);
        }
    }

    /**
     * A code for one thing must not do the other.
     *
     * Confirming a number at sign-up and resetting a password are asked for in
     * different places and are worth wildly different amounts. Somebody who can
     * make the shop text a code to a number they hold — which sign-up does by
     * design — must not be able to spend it on an account.
     */
    public function test_a_sign_up_code_cannot_reset_a_password(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $code = $this->codeFromTheTextMessage();

        $this->expectExceptionMessage('That code has expired. Ask for a new one.');
        $this->otp->verify('01712345678', OtpCode::PURPOSE_PASSWORD_RESET, $code);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $code = $this->codeFromTheTextMessage();

        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $code);

        $this->expectExceptionMessage('That code has expired. Ask for a new one.');
        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $code);
    }

    public function test_a_code_stops_working_once_it_has_expired(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $code = $this->codeFromTheTextMessage();

        $this->travel(OtpService::TTL_SECONDS + 1)->seconds();

        $this->expectExceptionMessage('That code has expired. Ask for a new one.');
        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $code);
    }

    /**
     * Six digits is a million guesses, which is nothing to a script. The cap is
     * what makes the code worth anything.
     */
    public function test_guessing_burns_the_code(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $code = $this->codeFromTheTextMessage();

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            try {
                $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, '000000');
            } catch (ValidationException $e) {
                // Expected; keep guessing.
            }
        }

        // Even the real code is no good now.
        $this->expectExceptionMessage('Too many wrong tries. Ask for a new code.');
        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $code);
    }

    /**
     * Otherwise pressing resend five times would leave five live codes, each
     * with its own five guesses — twenty-five tries against a six-digit number
     * instead of five.
     */
    public function test_asking_again_cancels_the_code_before_it(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $first = $this->codeFromTheTextMessage();

        $this->travel(OtpService::RESEND_SECONDS + 1)->seconds();
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $second = $this->codeFromTheTextMessage();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, OtpCode::live()->count());

        $this->expectExceptionMessage('is not right');
        $this->otp->verify('01712345678', OtpCode::PURPOSE_REGISTER, $first);
    }

    // --- what a code costs -----------------------------------------------

    public function test_codes_cannot_be_asked_for_back_to_back(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);

        $this->expectExceptionMessage('before asking for another code');
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
    }

    public function test_the_cooldown_passes(): void
    {
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);
        $this->travel(OtpService::RESEND_SECONDS + 1)->seconds();

        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER);

        $this->assertSame(2, OtpCode::count());
    }

    /**
     * The hourly cap is on the number, not the requester.
     *
     * Somebody changing IP between requests still cannot make the shop text one
     * person fifty times — which is both a bill and, from the other end, harassment.
     */
    public function test_one_number_cannot_be_texted_all_day(): void
    {
        for ($i = 0; $i < OtpService::MAX_PER_HOUR; $i++) {
            $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER, "10.0.0.{$i}");
            $this->travel(OtpService::RESEND_SECONDS + 1)->seconds();
        }

        $this->expectExceptionMessage('Too many codes requested for this number');
        $this->otp->issue('01712345678', OtpCode::PURPOSE_REGISTER, '10.0.0.99');
    }

    /** The cap counts every purpose: the cost is the same whatever it was for. */
    public function test_the_hourly_cap_is_not_dodged_by_switching_purpose(): void
    {
        User::factory()->create(['phone' => '01712345678']);

        for ($i = 0; $i < OtpService::MAX_PER_HOUR; $i++) {
            $this->otp->issue(
                '01712345678',
                $i % 2 ? OtpCode::PURPOSE_PASSWORD_RESET : OtpCode::PURPOSE_REGISTER
            );
            $this->travel(OtpService::RESEND_SECONDS + 1)->seconds();
        }

        $this->expectExceptionMessage('Too many codes requested for this number');
        $this->otp->issue('01712345678', OtpCode::PURPOSE_PASSWORD_RESET);
    }

    /** With no gateway there is nothing to verify a number with. */
    public function test_verification_is_not_claimed_when_no_code_can_be_sent(): void
    {
        config(['services.sms.enabled' => false]);

        $this->assertFalse(app(OtpService::class)->available());
    }
}
