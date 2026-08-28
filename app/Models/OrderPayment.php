<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt of money against an order.
 */
class OrderPayment extends Model
{
    /**
     * How the shop was paid.
     *
     * Cash and the mobile wallets people actually hand over at the counter or
     * send before a delivery. This is a record of money received, not a
     * gateway — nothing here charges anybody.
     */
    public const METHODS = [
        'cash' => 'Cash',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'bank' => 'Bank transfer',
        'card' => 'Card',
        'other' => 'Other',
    ];

    protected $fillable = [
        'order_id', 'amount', 'method', 'reference', 'note',
        'user_id', 'received_by_name', 'received_on',
    ];

    protected $appends = ['method_label'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_on' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }

    /** A negative row corrects one taken in error. */
    public function isCorrection(): bool
    {
        return (float) $this->amount < 0;
    }
}
