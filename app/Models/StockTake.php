<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One walk of one branch's shelves, and what it corrected.
 */
class StockTake extends Model
{
    protected $fillable = [
        'reference', 'store_id', 'user_id', 'counted_by_name', 'note',
        'lines_counted', 'lines_changed', 'net_units', 'value_change',
    ];

    protected function casts(): array
    {
        return [
            'value_change' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The corrections this count made, through the ledger that owns them. */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    public static function nextReference(): string
    {
        return 'COUNT-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
    }
}
