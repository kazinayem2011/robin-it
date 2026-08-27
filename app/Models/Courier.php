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
        'name', 'slug', 'driver', 'credentials', 'is_sandbox',
        'tracking_url_template', 'phone', 'note', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_sandbox' => 'boolean',
        'sort_order' => 'integer',
        // Live keys to a paid merchant account: encrypted at rest, and never
        // sent to the browser (see toArray below).
        'credentials' => 'encrypted:array',
    ];

    /** Typed by hand rather than booked through an API. */
    public const DRIVER_MANUAL = 'manual';

    /** Where {tracking} goes in a tracking URL. */
    public const PLACEHOLDER = '{tracking}';

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whether this carrier can book a parcel itself.
     *
     * A driver alone is not enough — without credentials there is nothing to
     * authenticate with, so it falls back to a number typed by hand.
     */
    public function canBook(): bool
    {
        return $this->driver !== self::DRIVER_MANUAL && filled($this->credentials);
    }

    /**
     * Credentials must never reach the browser.
     *
     * The screen needs to know *whether* they are set, not what they are, so
     * the array carries a flag in their place.
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        unset($data['credentials']);
        $data['has_credentials'] = filled($this->getRawOriginal('credentials'));
        $data['can_book'] = $this->canBook();

        return $data;
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
