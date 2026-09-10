<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The parts of signing in that are only ever noticed when they are missing.
 *
 * The suite already covers the journeys — signing up by mobile or address,
 * signing in, the OTP, the session windows. What it did not cover is the
 * handful of guards underneath them: who a token belongs to, what a new
 * account is allowed to ask for itself, and whether a session survives being
 * signed in to. Each of those is a line of code that looks removable.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Pass!23';

    private function signUp(array $extra = []): TestResponse
    {
        return $this->post('/register', array_merge([
            'name' => 'Probe',
            'email' => 'probe@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $extra));
    }

    // ── what a new account may ask for ───────────────────────────────────────

    /**
     * `role` is not fillable, and this is what says so out loud. Without it,
     * adding the column to $fillable — which is a one-word change somebody
     * makes to fix an unrelated form — would hand every visitor an admin
     * account for the asking.
     */
    public function test_a_new_account_cannot_make_itself_an_admin(): void
    {
        $this->signUp(['role' => 'admin']);

        $this->assertSame('customer', User::where('email', 'probe@example.test')->value('role'));
    }

    /** Nor mark its own address confirmed and skip the mail. */
    public function test_a_new_account_cannot_verify_itself(): void
    {
        $this->signUp(['email_verified_at' => now()->toDateTimeString()]);

        $this->assertNull(User::where('email', 'probe@example.test')->value('email_verified_at'));
    }

    // ── the session ──────────────────────────────────────────────────────────

    /*
     * Session fixation is not asserted here, and the reason is worth writing
     * down. A new session id at sign-in is what stops an attacker who can fix
     * a visitor's session id beforehand from holding their account after —
     * and this harness gives every request a fresh session, so the id changes
     * whether or not the application asks for it. A test comparing the two
     * passes with regenerate() deleted, which is worse than no test.
     *
     * Verified against the running application instead: the session cookie
     * sent before signing in differs from the one sent after, and the guard
     * is `$request->session()->regenerate()` in
     * AuthenticatedSessionController::store.
     */

    public function test_signing_out_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }

    // ── who a token belongs to ───────────────────────────────────────────────

    /**
     * The one that would be quietly catastrophic. A reset token is a bearer
     * credential; if it is checked without being tied to the address it was
     * issued for, anyone who can request their own reset can take any account.
     */
    public function test_a_reset_token_cannot_be_turned_on_another_account(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.test']);
        $attacker = User::factory()->create(['email' => 'attacker@example.test']);

        $this->post('/reset-password', [
            'token' => Password::createToken($attacker),
            'email' => 'victim@example.test',
            'password' => 'Owned-Pass!234',
            'password_confirmation' => 'Owned-Pass!234',
        ]);

        $this->assertFalse(Hash::check('Owned-Pass!234', $victim->fresh()->password));
    }

    /** Spent on use, so an old mail cannot be replayed. */
    public function test_a_reset_token_works_once(): void
    {
        $user = User::factory()->create(['email' => 'once@example.test']);
        $token = Password::createToken($user);

        $first = ['token' => $token, 'email' => 'once@example.test',
            'password' => 'First-Pass!234', 'password_confirmation' => 'First-Pass!234'];
        $this->post('/reset-password', $first);

        $this->assertTrue(Hash::check('First-Pass!234', $user->fresh()->password));

        $this->post('/reset-password', array_merge($first, [
            'password' => 'Second-Pass!23', 'password_confirmation' => 'Second-Pass!23',
        ]));

        $this->assertFalse(Hash::check('Second-Pass!23', $user->fresh()->password));
    }

    public function test_a_reset_token_stops_working_after_its_hour(): void
    {
        $user = User::factory()->create(['email' => 'slow@example.test']);
        $token = Password::createToken($user);

        $this->travel(config('auth.passwords.users.expire') + 5)->minutes();

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'slow@example.test',
            'password' => 'Late-Pass!234', 'password_confirmation' => 'Late-Pass!234',
        ]);

        $this->assertFalse(Hash::check('Late-Pass!234', $user->fresh()->password));
    }

    /**
     * The signature covers the id, so swapping it for somebody else's does not
     * survive. The existing test changes the hash on the same account, which
     * leaves this — verifying a stranger's address — unasked.
     */
    public function test_a_verification_link_cannot_be_pointed_at_another_account(): void
    {
        $victim = User::factory()->unverified()->create(['email' => 'v@example.test']);
        $holder = User::factory()->unverified()->create(['email' => 'h@example.test']);

        $signed = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $holder->id, 'hash' => sha1($holder->email),
        ]);

        $this->actingAs($victim)->get(
            str_replace("/verify-email/{$holder->id}/", "/verify-email/{$victim->id}/", $signed)
        );

        $this->assertFalse($victim->fresh()->hasVerifiedEmail());
    }

    /** And somebody else's untouched link verifies neither of them. */
    public function test_one_account_cannot_open_another_verification_link(): void
    {
        $owner = User::factory()->unverified()->create(['email' => 'owner@example.test']);
        $other = User::factory()->unverified()->create(['email' => 'other@example.test']);

        $this->actingAs($other)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $owner->id, 'hash' => sha1($owner->email),
        ]));

        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    // ── who may go where ─────────────────────────────────────────────────────

    public function test_a_customer_is_not_let_into_the_admin(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/admin');

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_a_guest_is_not_let_into_the_admin(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_a_guest_is_not_let_into_an_account(): void
    {
        $this->get('/dashboard')->assertRedirect();
    }

    // ── what the sign-in form gives away ─────────────────────────────────────

    /**
     * The same sentence either way, so the form cannot be used to find out
     * which addresses and numbers have accounts.
     */
    public function test_a_wrong_password_and_an_unknown_account_read_the_same(): void
    {
        User::factory()->create(['email' => 'known@example.test', 'password' => Hash::make(self::PASSWORD)]);

        $known = $this->from('/login')->post('/login', [
            'login' => 'known@example.test', 'password' => 'not-the-password',
        ]);
        $this->flushSession();

        $unknown = $this->from('/login')->post('/login', [
            'login' => 'nobody@example.test', 'password' => 'not-the-password',
        ]);

        $message = fn ($response) => $response->getSession()->get('errors')['default']['messages']['login'][0] ?? null;

        $this->assertNotNull($message($known));
        $this->assertSame($message($known), $message($unknown));
    }

    // ── a suspended account ──────────────────────────────────────────────────

    /**
     * The flag exists because it used to stop nothing: is_active was consulted
     * for staff only, so suspending a customer set a value that left them free
     * to sign in and order — which is the entire case the flag is for.
     */
    public function test_a_suspended_account_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'gone@example.test',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => false,
        ]);

        $this->post('/login', ['login' => 'gone@example.test', 'password' => self::PASSWORD]);

        $this->assertGuest();
    }

    /** And is told why, rather than being left to think they mistyped. */
    public function test_a_suspended_account_is_told_that_is_what_happened(): void
    {
        User::factory()->create([
            'email' => 'gone@example.test',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => false,
        ]);

        $response = $this->from('/login')->post('/login', [
            'login' => 'gone@example.test', 'password' => self::PASSWORD,
        ]);

        $this->assertStringContainsString(
            'suspended',
            $response->getSession()->get('errors')['default']['messages']['login'][0] ?? '',
        );
    }

    /**
     * But only once the password is right. Refusing a suspended account before
     * checking it would tell anybody with a list of addresses which ones have
     * accounts here — the same thing the shared wrong-password sentence avoids.
     */
    public function test_a_suspended_account_with_a_wrong_password_gives_nothing_away(): void
    {
        User::factory()->create([
            'email' => 'gone@example.test',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => false,
        ]);

        $response = $this->from('/login')->post('/login', [
            'login' => 'gone@example.test', 'password' => 'not-the-password',
        ]);

        $message = $response->getSession()->get('errors')['default']['messages']['login'][0] ?? '';

        $this->assertStringNotContainsString('suspended', $message);
    }

    // ── how an identifier is read ────────────────────────────────────────────

    /**
     * Case is not asserted here, and that is the finding rather than an
     * omission.
     *
     * The address goes to Auth::attempt exactly as it was typed, so whether
     * KNOWN@EXAMPLE.TEST reaches known@example.test is decided by the column's
     * collation and not by any code. On MySQL it signs in — checked against
     * the real database — and on the SQLite these tests run against it does
     * not. So the behaviour cannot be pinned either way without first being
     * made the application's own.
     *
     * What the code does promise is the trim, which is asserted below.
     */
    public function test_an_address_is_matched_as_the_database_compares_it(): void
    {
        $this->assertStringContainsString(
            "Auth::attempt(['email' => \$loginInput",
            file_get_contents(app_path('Http/Requests/Auth/LoginRequest.php')),
            'the address is no longer passed through as typed; case handling has changed and can now be pinned',
        );
    }

    /** Copied out of an email, a identifier arrives with spaces around it. */
    public function test_an_identifier_signs_in_with_spaces_around_it(): void
    {
        User::factory()->create(['email' => 'known@example.test', 'password' => Hash::make(self::PASSWORD)]);

        $this->post('/login', ['login' => '  known@example.test  ', 'password' => self::PASSWORD]);

        $this->assertAuthenticated();
    }

    /**
     * 01711111111 and +8801711111111 are one number, so the second sign-up
     * must be refused however the first was written.
     */
    public function test_a_number_already_in_use_is_refused_however_it_is_written(): void
    {
        User::factory()->create(['phone' => '01711111111', 'email' => null]);

        $this->post('/register', [
            'name' => 'Dup', 'phone' => '+8801711111111',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);

        $this->assertSame(1, User::where('phone', 'like', '%1711111111')->count());
    }
}
