<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change made to an order after it was placed.
 *
 * See the migration: allowing an edit means allowing staff to change what a
 * customer agreed to pay, so each one is written down rather than applied
 * silently.
 */
class OrderEdit extends Model
{
    protected $fillable = [
        'order_id', 'user_id', 'edited_by_name',
        'total_before', 'total_after', 'changes', 'reason',
    ];

    protected $casts = [
        'changes' => 'array',
        'total_before' => 'float',
        'total_after' => 'float',
    ];

    protected $appends = ['difference'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** What the change did to the bill. Negative means the order got cheaper. */
    public function getDifferenceAttribute(): float
    {
        return round($this->total_after - $this->total_before, 2);
    }
}
