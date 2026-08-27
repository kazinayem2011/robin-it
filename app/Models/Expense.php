<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Money the shop spent that is not stock.
 *
 * Buying stock is not an expense: those units are inventory until they sell,
 * and they reach the accounts as cost of goods sold on the order that sells
 * them, priced from the stock ledger. Recording a delivery here as well would
 * count the same money twice — and make every month with a big delivery look
 * like a loss it was not.
 */
class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'category', 'amount', 'description', 'incurred_on',
        'reference', 'note', 'supplier_id', 'user_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'incurred_on' => 'date',
    ];

    /**
     * What the money went on.
     *
     * There is deliberately no "stock" or "inventory" category — see above.
     * `delivery` is the courier's bill to the shop, which is a different thing
     * from the delivery fee collected from the customer.
     */
    public const CATEGORIES = [
        'rent' => 'Rent & premises',
        'salaries' => 'Salaries & wages',
        'utilities' => 'Utilities',
        'delivery' => 'Courier & delivery',
        'packaging' => 'Packaging & consumables',
        'marketing' => 'Marketing & advertising',
        'equipment' => 'Equipment & software',
        'fees' => 'Bank & transaction fees',
        'maintenance' => 'Repairs & maintenance',
        'other' => 'Other',
    ];

    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Costs belonging to a period, by the date they were incurred rather than
     * the date someone got round to typing them in.
     */
    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('incurred_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('incurred_on', '<=', $to));
    }
}
