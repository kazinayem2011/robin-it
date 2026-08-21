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

    /**
     * Payment methods the store actually accepts.
     *
     * Cash on delivery only for now. The API used to accept BKASH and NAGAD as
     * well, but nothing processes an online payment, so a crafted request could
     * create an order recorded as paid by bKash that no one had paid for. Add a
     * method here only once there is a gateway behind it.
     */
    public const PAYMENT_METHODS = ['COD'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The delivery address as a readable line.
     *
     * shipping_address is cast to an array, so echoing it straight into a Blade
     * template raised "htmlspecialchars(): argument must be of type string,
     * array given". That failure was swallowed by a try/catch around the send,
     * so order confirmation emails were failing silently.
     */
    public function getFormattedShippingAddressAttribute(): string
    {
        $address = $this->shipping_address ?? [];

        if (! is_array($address)) {
            return (string) $address;
        }

        return collect([
            $address['street_address'] ?? null,
            $address['zone'] ?? null,
            $address['city'] ?? null,
        ])->filter()->implode(', ') ?: 'Standard Delivery Address';
    }

    /** Recipient name captured at checkout, falling back to the account holder. */
    public function getRecipientNameAttribute(): string
    {
        return $this->shipping_address['name'] ?? $this->user?->name ?? 'Customer';
    }

    /** Contact number captured at checkout; guest orders have no user record. */
    public function getRecipientPhoneAttribute(): string
    {
        return $this->shipping_address['phone'] ?? $this->user?->phone ?? 'N/A';
    }

    /** Orders that still consume reserved stock. */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * A customer may call off an order until it has left the building.
     * Once it is shipped, cancellation is a support/returns conversation.
     */
    public function isCancellableByCustomer(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
