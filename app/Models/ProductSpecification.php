<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProductSpecification extends Model
{
    protected $fillable = ['product_id', 'group', 'name', 'value', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The order a spec sheet is read in, which is the order it was entered in —
     * never insertion order, or editing one row would send it to the bottom.
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Specs arranged for display: headings in the order they first appear, rows
     * in their own order within each.
     *
     * Ungrouped rows collect under an empty key rather than being dropped or
     * given an invented heading — a product whose specs predate grouping still
     * has to render, and it renders as the plain table it always was.
     *
     * @param  Collection<int, ProductSpecification>  $specifications
     * @return array<int, array{group: string, items: array<int, array{name: string, value: string}>}>
     */
    public static function grouped(Collection $specifications): array
    {
        return $specifications
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->groupBy(fn (self $spec) => trim((string) $spec->group))
            ->map(fn (Collection $rows, string $group) => [
                'group' => $group,
                'items' => $rows->map(fn (self $spec) => [
                    'name' => $spec->name,
                    'value' => $spec->value,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
