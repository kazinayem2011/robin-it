<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'min_spend',
        'max_discount',
        'usage_limit',
        'used_count',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'float',
        'min_spend' => 'float',
        'max_discount' => 'float',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /** Codes are always stored and compared uppercase. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    /** Look a coupon up the same way everywhere: trimmed and case-insensitive. */
    public static function findByCode(?string $code): ?self
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : static::where('code', $code)->first();
    }

    public function isValidForAmount(float $subtotal): array
    {
        if (! $this->is_active) {
            return ['valid' => false, 'message' => 'This coupon is no longer active.'];
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return ['valid' => false, 'message' => 'This coupon usage limit has been reached.'];
        }

        if ($subtotal < (float) $this->min_spend) {
            return [
                'valid' => false,
                'message' => 'Minimum spend of ৳'.number_format($this->min_spend).' required to use this coupon.',
            ];
        }

        return [
            'valid' => true,
            'discount' => $this->discountFor($subtotal),
            'coupon' => $this,
        ];
    }

    /**
     * SSOT for the discount amount. Checkout recalculates with this rather than
     * trusting whatever the browser posted back.
     */
    public function discountFor(float $subtotal): float
    {
        if ($this->discount_type === 'percent') {
            $discount = ($subtotal * (float) $this->discount_value) / 100;

            if ($this->max_discount !== null && $discount > (float) $this->max_discount) {
                $discount = (float) $this->max_discount;
            }
        } else {
            $discount = (float) $this->discount_value;
        }

        // Never discount below zero-cost.
        return round(min($discount, $subtotal), 2);
    }

    /**
     * Atomically record one redemption. Returns false when the limit was hit
     * concurrently, so the caller can back out rather than oversell the promo.
     */
    public function redeem(): bool
    {
        $query = static::whereKey($this->getKey());

        if ($this->usage_limit !== null) {
            $query->whereRaw('used_count < usage_limit');
        }

        $updated = $query->update(['used_count' => DB::raw('used_count + 1')]);

        if ($updated > 0) {
            $this->refresh();
        }

        return $updated > 0;
    }
}
