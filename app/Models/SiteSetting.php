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
    private const PRIVATE_PREFIXES = ['mail_', 'smtp_'];

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
     */
    public static function publicSettings(): array
    {
        return array_filter(
            self::getAllSettings(),
            fn ($key) => ! self::isPrivateKey($key),
            ARRAY_FILTER_USE_KEY
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
