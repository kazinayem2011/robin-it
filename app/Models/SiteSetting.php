<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'group',
        'label',
    ];

    /** Cache key for the whole settings map. */
    private const ALL_CACHE_KEY = 'all_site_settings';

    private const TTL = 3600;

    /**
     * Every setting the admin form is allowed to write, grouped as the form
     * groups them.
     *
     * This is an allowlist, and it is the security boundary. The save endpoint
     * used to validate only that each value was scalar and never looked at the
     * key, so an admin request could write any key at all — and because what
     * reaches the browser was decided by a *denylist* of name patterns, a key
     * that dodged those patterns (`payment_gateway_creds`, say) was published
     * into the props of every public page. Nothing outside this list can be
     * saved, and nothing outside PUBLIC_GROUPS is ever published.
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'general' => [
            'site_name',
            'site_tagline',
            'site_logo',
            'site_legal_name',
            'site_address',
            'footer_note',
        ],
        'contact' => [
            'hotline_number',
            'hotline_hours',
            'support_email',
            'sales_email',
            'service_center_address',
        ],
        'shipping' => [
            'shipping_inside_dhaka',
            'shipping_outside_dhaka',
            'free_shipping_threshold',
        ],
        /*
         * VAT. Off until a shop turns it on, because switching it on changes
         * what customers are charged and what the accounts report.
         *
         * `vat_inclusive` is the one that changes arithmetic rather than
         * paperwork: it says whether the prices on the shelf already contain
         * the tax. Reading one as the other misstates both the customer's
         * total and the shop's revenue.
         */
        'tax' => [
            'vat_enabled',
            'vat_rate',
            'vat_number',
            'vat_inclusive',
        ],
        'seo' => [
            'meta_title',
            'meta_description',
            'meta_keywords',
            'og_image',
            'google_analytics_id',
            'google_site_verification',
        ],
        'announcement' => [
            'announcement_text',
            'announcement_badge',
            'announcement_active',
        ],
        // Credentials. Writable from the Settings screen, never published.
        'mail' => [
            'mail_mailer',
            'mail_host',
            'mail_port',
            'mail_username',
            'mail_password',
            'mail_encryption',
            'mail_from_address',
            'mail_from_name',
        ],
        // Also credentials, and also never published: a gateway token in the
        // page source would let anyone send messages on the shop's account.
        'sms' => [
            'sms_enabled',
            'sms_token',
            'sms_url',
            'sms_api_key',
            'sms_sender_id',
            // Which messages the shop pays to send; see SmsService::EVENTS.
            'sms_on_order_placed',
            'sms_on_shipped',
            'sms_on_delivered',
            'sms_on_cancelled',
            'sms_on_returned',
            'sms_on_refund',
            'sms_on_payment_due',
        ],
    ];

    /** Groups whose values are safe in a browser. */
    private const PUBLIC_GROUPS = ['general', 'contact', 'shipping', 'tax', 'seo', 'announcement'];

    /**
     * Keys the admin Settings form may write.
     *
     * @return array<int, string>
     */
    public static function editableKeys(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Keys that may be sent to the browser.
     *
     * @return array<int, string>
     */
    public static function publicKeys(): array
    {
        $keys = array_merge(
            ...array_values(array_intersect_key(self::GROUPS, array_flip(self::PUBLIC_GROUPS)))
        );

        // Belt and braces: a key added to a public group but named like a
        // credential is still withheld.
        return array_values(array_filter($keys, fn ($key) => ! self::isPrivateKey($key)));
    }

    public static function get(string $key, $default = null)
    {
        return Cache::remember("site_setting_{$key}", self::TTL, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            return $setting ? $setting->value : $default;
        });
    }

    public static function set(string $key, $value, string $group = 'general', ?string $label = null)
    {
        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'label' => $label]
        );

        self::flushCache([$key]);

        return $setting;
    }

    public static function getAllSettings(): array
    {
        return Cache::remember(self::ALL_CACHE_KEY, self::TTL, function () {
            return static::pluck('value', 'key')->toArray();
        });
    }

    /**
     * Settings that must never reach the browser.
     *
     * Everything in this table used to be shared with every Inertia page, which
     * meant the SMTP host, username and password ciphertext were in the props of
     * every public page for every visitor.
     */
    /*
     * sms_ joins these: a gateway token is a credential, and the storefront
     * shares public settings with every visitor.
     */
    private const PRIVATE_PREFIXES = ['mail_', 'smtp_', 'sms_'];

    private const PRIVATE_SUFFIXES = ['_password', '_secret', '_token', '_key'];

    public static function isPrivateKey(string $key): bool
    {
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Settings safe to expose to the frontend — branding, SEO, shipping, ticker.
     *
     * Filtered by allowlist rather than by name pattern: a key nobody thought to
     * name like a credential is withheld too, simply because it is not on the
     * list of things this application publishes.
     */
    public static function publicSettings(): array
    {
        return array_intersect_key(
            self::getAllSettings(),
            array_flip(self::publicKeys())
        );
    }

    /**
     * Invalidate the settings cache.
     *
     * The admin save used to forget 'site_settings_all' while reads cached under
     * 'all_site_settings', so saved changes stayed invisible for up to an hour.
     *
     * @param  array<int, string>  $keys  individual keys to drop as well
     */
    public static function flushCache(array $keys = []): void
    {
        Cache::forget(self::ALL_CACHE_KEY);

        foreach ($keys as $key) {
            Cache::forget('site_setting_'.$key);
        }
    }
}
