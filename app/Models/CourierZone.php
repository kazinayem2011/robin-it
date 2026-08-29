<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address-to-courier-area mapping.
 *
 * See the migration for why these exist. The normalising happens here so it
 * cannot be forgotten by a caller: the same place typed three ways has to
 * resolve to one row, or the mapping is a lottery.
 */
class CourierZone extends Model
{
    protected $fillable = [
        'courier_id', 'city', 'zone', 'city_id', 'zone_id', 'area_id',
    ];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /**
     * What a place name is stored and matched as.
     *
     * Case and stray spaces are noise. So are the several ways people write a
     * district — "Dhaka", "dhaka", "DHAKA " — and none of them should be a
     * different row.
     */
    public static function normalise(?string $place): ?string
    {
        $clean = trim(mb_strtolower((string) $place));

        // Collapse runs of whitespace: "cox's  bazar" is "cox's bazar".
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return $clean === '' ? null : $clean;
    }

    public function setCityAttribute($value): void
    {
        $this->attributes['city'] = self::normalise($value);
    }

    public function setZoneAttribute($value): void
    {
        $this->attributes['zone'] = self::normalise($value);
    }
}
