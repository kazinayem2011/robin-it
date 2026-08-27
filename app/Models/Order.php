<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'session_id', 'order_number', 'subtotal',
        'shipping_fee', 'discount', 'coupon_code', 'total', 'status',
        'payment_method', 'payment_status', 'shipping_address',
        'stock_released_at', 'stock_returned_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'subtotal' => 'float',
        'shipping_fee' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'stock_released_at' => 'datetime',
        'stock_returned_at' => 'datetime',
    ];

    /** Order lifecycle states, in the order the customer sees them. */
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    /**
     * States where the order no longer holds reserved stock and cannot move on.
     * A returned order has already had its units accounted for, item by item.
     */
    public const TERMINAL_STATUSES = ['returned'];

    /** Only a delivered order can be returned — nothing else has reached the customer. */
    public const RETURNABLE_FROM = ['delivered'];

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

    /**
     * What the goods on this order cost the shop.
     *
     * Null when any line has no known cost — a partial figure presented as a
     * total is worse than no figure, because it reads as profit that is not
     * there. `uncostedItemCount` says how many lines are in the way.
     */
    public function getCostTotalAttribute(): ?float
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty() || $items->contains(fn ($item) => $item->unit_cost === null)) {
            return null;
        }

        return round($items->sum(fn ($item) => $item->cost_total), 2);
    }

    /**
     * Goods sold less what they cost, after any discount.
     *
     * Delivery is deliberately excluded on both sides: the fee is collected on
     * behalf of the courier and paying them is an expense the shop does not yet
     * record, so counting the fee as income would overstate this.
     */
    public function getGrossProfitAttribute(): ?float
    {
        $cost = $this->cost_total;

        if ($cost === null) {
            return null;
        }

        return round((float) $this->subtotal - (float) $this->discount - $cost, 2);
    }

    /** Lines whose cost is unknown, so a reader can see why a figure is missing. */
    public function getUncostedItemCountAttribute(): int
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->filter(fn ($item) => $item->unit_cost === null)->count();
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

    public function isReturned(): bool
    {
        return $this->status === 'returned';
    }

    /** Whether the order's units are currently off the shelf and owed back. */
    public function holdsReservedStock(): bool
    {
        return $this->stock_released_at === null && $this->stock_returned_at === null;
    }

    public function isReturnable(): bool
    {
        return in_array($this->status, self::RETURNABLE_FROM, true)
            && $this->stock_returned_at === null;
    }
}
