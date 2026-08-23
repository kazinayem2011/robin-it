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
    protected $fillable = [
        'name', 'contact_name', 'phone', 'email', 'address', 'note', 'is_active',
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

    /** Total units ever received from this supplier. */
    public function getUnitsReceivedAttribute(): int
    {
        return (int) $this->receipts()->sum('total_quantity');
    }
}
