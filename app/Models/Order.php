<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'session_id', 'order_number', 'subtotal',
        'shipping_fee', 'discount', 'vat_amount', 'vat_rate', 'vat_inclusive', 'coupon_code',
        'coupon_discount_type', 'coupon_discount_value', 'total', 'status',
        'payment_method', 'payment_status', 'shipping_address',
        'stock_released_at', 'stock_returned_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'subtotal' => 'float',
        'shipping_fee' => 'float',
        'discount' => 'float',
        'vat_amount' => 'float',
        'vat_rate' => 'float',
        'vat_inclusive' => 'boolean',
        'coupon_discount_value' => 'float',
        'total' => 'float',
        'stock_released_at' => 'datetime',
        'stock_returned_at' => 'datetime',
    ];

    /** Order lifecycle states, in the order the customer sees them. */
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    /**
     * States an order cannot move out of.
     *
     * A returned order has had its units accounted for, item by item. A
     * cancelled one has already handed its units back, and un-cancelling it
     * would rewrite a decision the customer was told about — the replacement
     * for "I changed my mind again" is a new order, which the returned stock
     * can immediately cover.
     */
    public const TERMINAL_STATUSES = ['cancelled', 'returned'];

    /** Whether this order has reached a state it cannot leave. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * States a return can be raised from — anything that has left the building.
     *
     * `shipped` is here as well as `delivered` because a parcel that comes back
     * from the courier is goods returning, not an order that never happened.
     * Cancelling one used to credit the shelf with every unit, intact, which is
     * a guess: the return flow asks how many actually came back and in what
     * condition, so damaged units are written off instead of resold.
     */
    public const RETURNABLE_FROM = ['shipped', 'delivered'];

    /**
     * States an order can still be cancelled from.
     *
     * Cancellation restores stock on the assumption that the goods never left,
     * so it stops at the point they do. After dispatch it is a return.
     */
    public const CANCELLABLE_FROM = ['pending', 'processing'];

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

    /**
     * The promo code's terms as they stood when this order used them, written
     * for a human.
     *
     * The coupon itself may have been edited or deleted since, so this reads
     * from what was frozen onto the order rather than looking the code up.
     */
    public function getCouponTermsAttribute(): ?string
    {
        if (! $this->coupon_code || $this->coupon_discount_type === null) {
            return null;
        }

        return $this->coupon_discount_type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->coupon_discount_value, 2), '0'), '.').'% off'
            : '৳'.number_format((float) $this->coupon_discount_value).' off';
    }

    /**
     * How the VAT on this order should be worded.
     *
     * "Includes VAT" and "VAT added" are different statements about the same
     * number, and which one is true was decided when the order was placed.
     */
    public function getVatLabelAttribute(): ?string
    {
        if ((float) $this->vat_amount <= 0) {
            return null;
        }

        $rate = $this->vat_rate ? rtrim(rtrim(number_format((float) $this->vat_rate, 2), '0'), '.') : null;
        $suffix = $rate ? " @ {$rate}%" : '';

        return $this->vat_inclusive ? "Includes VAT{$suffix}" : "VAT{$suffix}";
    }

    /** Orders that still consume reserved stock. */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Whether this order can still be cancelled rather than returned.
     *
     * The same answer for the customer and the admin: cancellation restores
     * stock as though the goods never left, so it stops at the point they do.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_FROM, true);
    }

    /**
     * A customer may call off an order until it has left the building.
     * Once it is shipped, cancellation is a support/returns conversation.
     */
    public function isCancellableByCustomer(): bool
    {
        return $this->isCancellable();
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
