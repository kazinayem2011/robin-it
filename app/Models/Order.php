<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'session_id', 'order_number', 'subtotal',
        'shipping_fee', 'discount', 'vat_amount', 'vat_rate', 'vat_inclusive', 'coupon_code',
        'coupon_discount_type', 'coupon_discount_value', 'total', 'status',
        'courier_id', 'tracking_number', 'dispatched_at',
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
        'dispatched_at' => 'datetime',
        'stock_released_at' => 'datetime',
        'stock_returned_at' => 'datetime',
    ];

    /**
     * Derived from the courier and the consignment number, so it travels with
     * the order rather than every screen having to build it.
     */
    protected $appends = ['tracking_url', 'amount_paid', 'amount_due', 'payment_state'];

    /** Order lifecycle states, in the order the customer sees them. */
    /**
     * An order number as the customer is likely to give it back.
     *
     * Six screens print it as "#ORD-ABC123" — the confirmation page they land
     * on after paying among them — so that hash is what gets copied, and it is
     * decoration: it reads "number", it is not part of the value. Stored
     * numbers are uppercase, and a pasted one tends to bring whitespace with
     * it.
     */
    public static function normalizeNumber(?string $number): string
    {
        return strtoupper(trim(ltrim(trim((string) $number), '#')));
    }

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

    /**
     * 'partial' joined these when payments became amounts rather than a flag:
     * a deposit on a build is neither unpaid nor paid, and calling it either
     * loses money.
     */
    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid', 'pending', 'refunded'];

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

    public function courier()
    {
        return $this->belongsTo(Courier::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class)->latest('refunded_on');
    }

    /** Everything given back on this order so far. */
    public function getRefundedTotalAttribute(): float
    {
        $refunds = $this->relationLoaded('refunds') ? $this->refunds : $this->refunds()->get();

        return round((float) $refunds->sum('amount'), 2);
    }

    /**
     * What is still left to give back.
     *
     * Never negative: over-refunding is refused when it is attempted, and a
     * historic overshoot should read as nothing left rather than as a debt the
     * customer owes.
     */
    public function getRefundableAmountAttribute(): float
    {
        return round(max(0, (float) $this->total - $this->refunded_total), 2);
    }

    public function isFullyRefunded(): bool
    {
        return (float) $this->total > 0 && $this->refundable_amount <= 0;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('received_on')->orderBy('id');
    }

    /**
     * What the shop has actually received against this order.
     *
     * The sum of the payment rows, corrections included — a negative row is a
     * payment taken in error being put back, and it belongs in the total the
     * same way the mistake did.
     */
    public function getAmountPaidAttribute(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /**
     * What the customer still owes.
     *
     * Refunds count against what was paid: money given back was not kept, so
     * an order paid in full and then half refunded is half owing again if it
     * has not been cancelled. Never negative — an overpayment is money to give
     * back, which is a refund, not a debt the shop is owed.
     */
    public function getAmountDueAttribute(): float
    {
        $net = $this->amount_paid - $this->refunded_total;

        return round(max(0, (float) $this->total - $net), 2);
    }

    /**
     * unpaid, partial or paid — worked out, never stored on its own.
     *
     * payment_status is kept in step with this when a payment is recorded, but
     * this is the answer: a stored flag and a column of amounts can disagree,
     * and when they do the amounts are right.
     */
    public function getPaymentStateAttribute(): string
    {
        if ((float) $this->total <= 0) {
            return 'paid';
        }

        if ($this->amount_due <= 0) {
            return 'paid';
        }

        return $this->amount_paid > 0 ? 'partial' : 'unpaid';
    }

    public function isFullyPaid(): bool
    {
        return $this->payment_state === 'paid';
    }

    /**
     * Where the customer can watch this parcel, when the carrier offers a
     * public lookup. Null is a real answer: some carriers have none, and the
     * consignment number is still worth quoting down the phone.
     */
    public function getTrackingUrlAttribute(): ?string
    {
        return $this->courier?->trackingUrlFor($this->tracking_number);
    }

    /** Whether this order has left with a carrier and a number to chase. */
    public function isDispatched(): bool
    {
        return $this->dispatched_at !== null;
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
        return $this->notifiablePhone() ?? 'N/A';
    }

    /**
     * A number worth texting, or null.
     *
     * recipient_phone answers "N/A" so an invoice reads properly, and that is
     * the right answer for a printed page and a terrible one for a gateway:
     * the shop would spend an attempt, and a log line, dialling the letters
     * N and A. Anything sending a message asks for this instead.
     */
    public function notifiablePhone(): ?string
    {
        $phone = $this->shipping_address['phone'] ?? $this->user?->phone ?? null;

        return filled($phone) ? (string) $phone : null;
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
