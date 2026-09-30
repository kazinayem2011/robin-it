<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of an order from one status to another, and who made it.
 *
 * Written by Order's own save hook, so every way an order moves — the admin,
 * a customer cancelling, anything automatic — is recorded without each of them
 * having to remember to.
 */
class OrderStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'user_id', 'by_name'];

    protected $casts = ['created_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
