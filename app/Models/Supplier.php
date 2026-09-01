<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone the shop buys stock from.
 *
 * Deliveries used to carry a typed supplier name, which meant "Star Tech" and
 * "Star Tech Ltd" were different suppliers to every report, and nobody could
 * look up who to call about a faulty batch.
 */
class Supplier extends Model
{
    /** Someone the shop buys from. */
    public const TRADE = 'trade';

    /**
     * The standing source for stock that was already on the shelf when the
     * shop started keeping books. Exactly one of these exists, it is not
     * somebody to ring about a faulty batch, and it cannot be deleted —
     * removing it would orphan the deliveries that account for the opening
     * shelf of every product in the shop.
     */
    public const OPENING = 'opening';

    protected $fillable = [
        'name', 'kind', 'contact_name', 'phone', 'email', 'address', 'note', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function receipts()
    {
        return $this->hasMany(StockReceipt::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Real suppliers — what a purchase order may be written to. */
    public function scopeTrade($query)
    {
        return $query->where('kind', self::TRADE);
    }

    /** The opening-balance source, made if a migration has not already. */
    public static function openingBalance(): self
    {
        return static::firstOrCreate(
            ['kind' => self::OPENING],
            [
                'name' => 'Opening balance',
                'note' => 'Stock already on the shelf when the shop started keeping books. Not a supplier.',
                'is_active' => true,
            ]
        );
    }

    public function isOpeningBalance(): bool
    {
        return $this->kind === self::OPENING;
    }

    /** Total units ever received from this supplier. */
    public function getUnitsReceivedAttribute(): int
    {
        return (int) $this->receipts()->sum('total_quantity');
    }
}
