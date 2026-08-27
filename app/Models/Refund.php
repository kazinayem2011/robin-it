<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Money given back on an order.
 *
 * An event, not a flag. `payment_status = 'refunded'` recorded that something
 * happened and nothing about what: no amount, no date, no method, no reason,
 * and no way to express giving back part of an order.
 */
class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'amount', 'method', 'reference', 'reason', 'note', 'user_id', 'refunded_on',
    ];

    protected $casts = [
        'amount' => 'float',
        'refunded_on' => 'date',
    ];

    /**
     * How the money went back.
     *
     * `cod_not_collected` is the common one on a cash-on-delivery shop: the
     * parcel came back before the rider took any money, so nothing is actually
     * refunded — but the order still has to show that the customer owes
     * nothing, and a zero-value entry says so where silence would not.
     */
    public const METHODS = [
        'cod_not_collected' => 'Cash never collected',
        'cash' => 'Cash',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'bank' => 'Bank transfer',
        'store_credit' => 'Store credit',
        'other' => 'Other',
    ];

    /** Why it was given back — what a report is grouped by. */
    public const REASONS = [
        'returned' => 'Goods returned',
        'damaged' => 'Arrived damaged',
        'wrong_item' => 'Wrong item sent',
        'cancelled' => 'Order cancelled',
        'undelivered' => 'Could not be delivered',
        'price_adjustment' => 'Price adjustment',
        'goodwill' => 'Goodwill',
        'other' => 'Other',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst((string) $this->reason);
    }

    /**
     * Refunds belonging to a period, by the date the money moved rather than
     * the date someone recorded it.
     */
    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('refunded_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('refunded_on', '<=', $to));
    }

    /**
     * Money that actually left the business.
     *
     * Cash never collected is a refund on paper only — the customer was never
     * charged — so counting it would double the loss on a cash-on-delivery
     * order that came back.
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('method', '!=', 'cod_not_collected');
    }
}
