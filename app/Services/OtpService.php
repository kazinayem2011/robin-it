<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\OtpCode;
use App\Support\BrandDetails;
use App\Support\SmsTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Proving that somebody holds the number they typed.
 *
 * Sign-up asked for a mobile number and believed it. Everything after that —
 * the confirmation, the dispatch note, the rider's phone call, the warranty
 * record — goes to that number, so a typo or somebody else's number surfaces
 * as a stranger taking delivery of a parcel.
 *
 * The same codes give a customer a way back into an account. Password reset was
 * email only, and a great many people here sign up with an address they will
 * never open again; when the password went, so did the order history and every
 * warranty claim attached to it.
 *
 * Every rule in here is about the same two things: a six-digit code is cheap to
 * guess, and every send costs the shop money.
 */
class OtpService
{
    /** Long enough to read a text and type it, short enough to be worthless later. */
    public const TTL_SECONDS = 300;

    /** Wrong guesses before the code is burned and a new one must be asked for. */
    public const MAX_ATTEMPTS = 5;

    /** Between one request and the next, for the same number. */
    public const RESEND_SECONDS = 60;

    /** Codes one number may ask for in an hour, whoever is asking. */
    public const MAX_PER_HOUR = 5;

    public function __construct(private readonly SmsService $sms) {}

    /**
     * Whether a phone can be verified at all right now.
     *
     * With no SMS gateway there is no way to send a code, so demanding one
     * would lock every new customer out of the shop. Sign-up then works as it
     * did before: the number is recorded and taken on trust.
     *
     * This is the honest answer rather than a switch, because a switch labelled
     * "verify phone numbers" that cannot send a text is a lie in the settings.
     */
    public function available(): bool
    {
        return $this->sms->enabled();
    }

    /**
     * Send a code, and return when another may be asked for.
     *
     * @return array{sent: bool, resend_in: int}
     */
    public function issue(string $phone, string $purpose, ?string $ip = null): array
    {
        $phone = $this->normalise($phone);

        $this->guardAgainstFlooding($phone, $purpose);

        $code = $this->freshCode();

        DB::transaction(function () use ($phone, $purpose, $code, $ip) {
            /*
             * Asking again cancels what came before.
             *
             * Otherwise a handful of requests leaves a handful of live codes,
             * each with its own five guesses — the attempt cap would be
             * multiplied by however many times somebody pressed resend.
             */
            OtpCode::for($phone, $purpose)->live()->update(['used_at' => now()]);

            OtpCode::create([
                'phone' => $phone,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addSeconds(self::TTL_SECONDS),
                'ip' => $ip,
            ]);
        });

        $sent = $this->sms->send($phone, SmsTemplates::verificationCode($code, $purpose, BrandDetails::name()));

        if (! $sent) {
            // The row stands. A gateway that failed on this attempt may work on
            // the next, and the customer can press resend once the cooldown is
            // up rather than starting the form again.
            Log::warning("Could not send the {$purpose} code to {$phone}.");
        }

        return ['sent' => $sent, 'resend_in' => self::RESEND_SECONDS];
    }

    /**
     * Check a code, and spend it.
     *
     * Throws rather than returning false: every failure here has a different
     * thing the customer should do next, and a bare false loses that.
     *
     * @param  string  $field  which form field the message belongs against
     */
    public function verify(string $phone, string $purpose, string $code, string $field = 'code'): void
    {
        $phone = $this->normalise($phone);

        $record = OtpCode::for($phone, $purpose)
            ->live()
            ->latest('id')
            ->first();

        if (! $record) {
            $this->fail($field, 'That code has expired. Ask for a new one.');
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            $record->forceFill(['used_at' => now()])->save();

            $this->fail($field, 'Too many wrong tries. Ask for a new code.');
        }

        if (! Hash::check(trim($code), $record->code_hash)) {
            $record->increment('attempts');

            $left = self::MAX_ATTEMPTS - $record->attempts;

            $this->fail($field, $left > 0
                ? "That code is not right. {$left} ".($left === 1 ? 'try' : 'tries').' left.'
                : 'That code is not right. Ask for a new one.');
        }

        /*
         * Spent the moment it works. Without this the same code could be
         * replayed — most usefully by whoever is sitting on the network
         * between the customer and the shop.
         */
        $record->forceFill(['used_at' => now()])->save();
    }

    /**
     * How long until this number may ask for another code, or zero.
     */
    public function resendIn(string $phone, string $purpose): int
    {
        $last = OtpCode::for($this->normalise($phone), $purpose)->latest('id')->first();

        if (! $last) {
            return 0;
        }

        $elapsed = $last->created_at->diffInSeconds(now());

        return (int) max(0, self::RESEND_SECONDS - $elapsed);
    }

    /**
     * Delete codes too old to matter.
     *
     * Recent history is worth keeping — it answers "did we actually send that"
     * and shows a burst of requests for what it is — but a code from last month
     * answers nothing and is one more row holding a phone number.
     */
    public function prune(int $days = 30): int
    {
        return OtpCode::where('created_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Stop one number being used to burn the shop's SMS credit.
     *
     * Both limits are on the number rather than the requester: an attacker
     * changing IP still cannot make the shop send fifty texts to somebody.
     */
    private function guardAgainstFlooding(string $phone, string $purpose): void
    {
        $wait = $this->resendIn($phone, $purpose);

        if ($wait > 0) {
            $this->fail('phone', "Please wait {$wait} seconds before asking for another code.");
        }

        $recent = OtpCode::where('phone', $phone)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($recent >= self::MAX_PER_HOUR) {
            $this->fail('phone', 'Too many codes requested for this number. Try again in an hour.');
        }
    }

    /**
     * Six digits from a source worth trusting.
     *
     * random_int, not rand: the point of the code is that it cannot be guessed,
     * and a predictable generator makes the whole thing decoration.
     *
     * Leading zeros are kept — dropping them would quietly make some codes five
     * digits and shrink the space they are drawn from.
     */
    private function freshCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function normalise(string $phone): string
    {
        return PhoneHelper::normalizeBdPhone($phone) ?? trim($phone);
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
