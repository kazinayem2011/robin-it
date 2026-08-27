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
        'expense_category_id', 'amount', 'description', 'incurred_on',
        'reference', 'note', 'supplier_id', 'user_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'incurred_on' => 'date',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * The category's name, or a placeholder when the category was removed.
     *
     * Deleting a category nulls the reference rather than the expense: the
     * money was still spent, and losing the record of it to tidy up a list
     * would be the wrong trade.
     */
    public function getCategoryLabelAttribute(): string
    {
        return $this->category?->name ?? 'Uncategorised';
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
