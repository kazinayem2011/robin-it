<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sending an SMS.
 *
 * The shop had six well-built email templates and no SMS at all, which in
 * Bangladesh means a large share of customers were told nothing: the order
 * confirmation, the dispatch note and the tracking link all went to an inbox
 * nobody opened.
 *
 * The gateway handling here is lifted from a sister project that has been
 * through it in production — GreenWeb with an older gateway behind it, the
 * response-reading, the IPv4 pin and the timeout rule are all scars, not
 * design. They are kept rather than rewritten.
 *
 * Credentials come from the Settings screen first and .env second, matching
 * how SMTP already works here, so a shop can change providers without a deploy.
 */
class SmsService
{
    /** What the Settings screen stores. */
    public const KEYS = [
        'sms_enabled',
        'sms_token',
        'sms_url',
        'sms_api_key',
        'sms_sender_id',
    ];

    /**
     * Send one message.
     *
     * Never throws. A shop's checkout must not fail because a gateway is down,
     * and every caller here is a notification rather than the thing the
     * customer asked for.
     */
    public function send(?string $to, string $message): bool
    {
        if (blank($to) || blank($message)) {
            return false;
        }

        if (! $this->enabled()) {
            return false;
        }

        $phone = $this->gatewayNumber($to);

        if (! $phone) {
            Log::warning("SMS not sent: {$to} is not a number we can dial.");

            return false;
        }

        // Gateways charge per 70 characters for unicode and reject most emoji
        // outright, so they are stripped rather than sent and refused.
        $message = preg_replace(
            '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{27BF}]/u',
            '',
            $message
        ) ?? $message;

        if ($token = $this->setting('sms_token')) {
            return $this->viaGreenWeb($phone, $message, $token);
        }

        $url = $this->setting('sms_url');
        $key = $this->setting('sms_api_key');
        $sender = $this->setting('sms_sender_id');

        if ($url && $key && $sender) {
            return $this->viaGenericGateway($phone, $message, $url, $key, $sender);
        }

        /*
         * No gateway configured. Locally that is normal — the message goes to
         * the log so the flow can be followed end to end without a provider
         * account. On a live site it is a misconfiguration worth saying out
         * loud rather than failing quietly.
         */
        if (config('services.sms.log_fallback')) {
            Log::info("SMS (logged, not sent) to {$phone}: {$message}");

            return true;
        }

        Log::warning("SMS skipped for {$phone}: no gateway is configured.");

        return false;
    }

    public function enabled(): bool
    {
        // Off by default: a shop that has not set this up should not be
        // silently failing to send on every order.
        return (bool) ($this->setting('sms_enabled') ?? false);
    }

    /**
     * A setting from the admin, falling back to .env.
     */
    private function setting(string $key): ?string
    {
        if (app()->runningUnitTests()) {
            return config('services.sms.'.str_replace('sms_', '', $key));
        }

        $stored = SiteSetting::get($key);

        return filled($stored)
            ? (string) $stored
            : config('services.sms.'.str_replace('sms_', '', $key));
    }

    /**
     * 8801XXXXXXXXX, which is what every Bangladeshi gateway wants.
     *
     * PhoneHelper already knows how to read the half-dozen ways people write a
     * number; this only puts the country code back on the front of the result.
     */
    public function gatewayNumber(string $to): ?string
    {
        $local = PhoneHelper::normalizeBdPhone($to);

        if (! $local || ! PhoneHelper::isValidBdPhone($local)) {
            return null;
        }

        return '88'.$local;
    }

    private function viaGreenWeb(string $phone, string $message, string $token): bool
    {
        $url = $this->setting('sms_url')
            ?: config('services.sms.greenweb_url', 'http://api.greenweb.com.bd/api.php?json');

        try {
            $response = Http::connectTimeout(15)
                ->timeout(30)
                ->withOptions(['verify' => false] + $this->ipv4Options())
                ->asForm()
                ->post($url, ['to' => $phone, 'message' => $message, 'token' => $token]);

            return $this->readReply('GreenWeb', $phone, $response);
        } catch (\Throwable $e) {
            return $this->handleException('GreenWeb', $phone, $e);
        }
    }

    private function viaGenericGateway(
        string $phone,
        string $message,
        string $url,
        string $key,
        string $sender
    ): bool {
        try {
            // Bengali needs the unicode message type, and costs more per part.
            $isUnicode = strlen($message) !== mb_strlen($message, 'utf-8');

            $response = Http::connectTimeout(15)
                ->timeout(60)
                ->withOptions($this->ipv4Options())
                ->get($url, [
                    'api_key' => $key,
                    'type' => $isUnicode ? 'unicode' : 'text',
                    'phone' => $phone,
                    'senderid' => $sender,
                    'message' => $message,
                ]);

            return $this->readReply('SMS gateway', $phone, $response);
        } catch (\Throwable $e) {
            return $this->handleException('SMS gateway', $phone, $e);
        }
    }

    /**
     * The first call on a cold box stalls on an IPv6 lookup for the gateway,
     * long enough to trip the timeout after the SMS has already gone out.
     * Pinning the lookup to IPv4 keeps that first send as quick as the rest.
     */
    private function ipv4Options(): array
    {
        if (! defined('CURLOPT_IPRESOLVE') || ! defined('CURL_IPRESOLVE_V4')) {
            return [];
        }

        return ['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]];
    }

    private function readReply(string $gateway, string $phone, $response): bool
    {
        $body = trim($response->body());

        if ($this->accepted($response->json(), $body, $response->successful())) {
            Log::info("SMS sent to {$phone} via {$gateway}: [{$body}]");

            return true;
        }

        Log::warning("{$gateway} rejected the message for {$phone}: [{$body}]");

        return false;
    }

    /**
     * Whether the gateway took the message.
     *
     * They answer in a dozen shapes — JSON, plain text, a bare message id — so
     * a send only counts as failed when the reply actually names a failure.
     * Anything else on a 2xx is taken as accepted, because telling a shop the
     * message never went out while the customer's phone is buzzing with it is
     * the worse of the two mistakes.
     */
    private function accepted($decoded, string $body, bool $httpOk): bool
    {
        $failure = '/error|fail|invalid|unauthori|insufficient|balance|blocked|reject|expire|denied|missing|not\s*found|wrong|limit|duplicate/i';
        $success = '/success|sent|submit|queued|accepted|deliver|\bok\b|\btrue\b|\b1000\b|status_code"?\s*:\s*"?200/i';

        $status = '';

        if (is_array($decoded)) {
            foreach (['status', 'Status', 'response_code', 'status_code', 'result', 'error_code'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string) $decoded[$key]) !== '') {
                    $status = trim((string) $decoded[$key]);
                    break;
                }
            }
        }

        foreach ([$status, $this->maskZeroErrorCodes($body)] as $haystack) {
            if ($haystack === '') {
                continue;
            }

            // Failure first: "Invalid Token" also contains "ok".
            if (preg_match($failure, $haystack)) {
                return false;
            }

            if (preg_match($success, $haystack)) {
                return true;
            }
        }

        // Some gateways answer with nothing but a message id.
        if (is_numeric($body) && strlen($body) > 5) {
            return true;
        }

        if ($httpOk) {
            Log::warning("Unrecognised SMS gateway reply, treating it as accepted: [{$body}]");
        }

        return $httpOk;
    }

    /**
     * "error": 0 and its cousins mean the opposite of an error, so they must
     * not be read as one when scanning the raw body.
     */
    private function maskZeroErrorCodes(string $body): string
    {
        return preg_replace('/"?error(_code|_no|s)?"?\s*[:=]\s*"?0"?/i', 'noerr', $body) ?? $body;
    }

    /**
     * A read timeout is not proof of failure: the gateway takes the message
     * before it answers, so the SMS still lands. Anything else — DNS, a refused
     * connection, TLS — means the request never arrived, and that is a real
     * failure.
     */
    private function handleException(string $gateway, string $phone, \Throwable $e): bool
    {
        $reason = $e->getMessage();

        if (preg_match('/curl error (?:28|operation timed out)|timed out|timeout/i', $reason)) {
            Log::warning("{$gateway} timed out for {$phone}, treating the SMS as sent: {$reason}");

            return true;
        }

        Log::error("{$gateway} failed for {$phone}: {$reason}");

        return false;
    }
}
