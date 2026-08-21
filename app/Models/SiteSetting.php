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
