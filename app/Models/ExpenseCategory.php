<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What a shop spends money on.
 *
 * These were a constant in the Expense model — a list picked when the feature
 * was written, which no shop could change without a deploy. Every business
 * spends on something the next one does not.
 *
 * One thing has not changed: buying stock still does not belong here. Units
 * bought are inventory until they sell, and reach the accounts as cost of goods
 * sold on the order that sells them. A category named for stock would count the
 * same money twice, so the form warns before letting one be created.
 */
class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'note', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Words that suggest someone is about to record inventory as an expense.
     *
     * Used to warn, never to refuse: a shop may have a good reason, and being
     * told why is more use than being blocked.
     */
    public const INVENTORY_WORDS = ['stock', 'inventory', 'purchase', 'goods', 'product'];

    public static function looksLikeInventory(string $name): bool
    {
        $name = mb_strtolower($name);

        foreach (self::INVENTORY_WORDS as $word) {
            if (str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The order the admin arranged them in, then alphabetical. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** A slug nothing else is using, derived from the name. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
