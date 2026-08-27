<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A delivery company the shop hands parcels to.
 *
 * Seeded with the carriers most Bangladeshi shops use, and editable, because
 * carriers change their tracking URLs and a shop should not need a deploy to
 * correct one.
 */
class Courier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'tracking_url_template', 'phone', 'note', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Where {tracking} goes in a tracking URL. */
    public const PLACEHOLDER = '{tracking}';

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The page that shows where a given parcel is, or null when this carrier
     * has no public lookup — in which case the number is still worth printing
     * and quoting down the phone.
     */
    public function trackingUrlFor(?string $trackingNumber): ?string
    {
        $template = trim((string) $this->tracking_url_template);

        if ($template === '') {
            return null;
        }

        if (! str_contains($template, self::PLACEHOLDER)) {
            // Some carriers only offer a search page with no per-parcel URL.
            return $template;
        }

        if (blank($trackingNumber)) {
            return null;
        }

        return str_replace(self::PLACEHOLDER, rawurlencode($trackingNumber), $template);
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'courier';
        $slug = $base;
        $suffix = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
