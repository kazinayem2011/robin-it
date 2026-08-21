<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'session_id', 'order_number', 'subtotal',
        'shipping_fee', 'discount', 'coupon_code', 'total', 'status',
        'payment_method', 'payment_status', 'shipping_address',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'subtotal' => 'float',
        'shipping_fee' => 'float',
        'discount' => 'float',
        'total' => 'float',
    ];

    /** Order lifecycle states, in the order the customer sees them. */
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    public const PAYMENT_STATUSES = ['unpaid', 'paid', 'pending', 'refunded'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Orders that still consume reserved stock. */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
