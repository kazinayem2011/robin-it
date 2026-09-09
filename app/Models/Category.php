<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'parent_id',
        'brand_id',
        'name',
        'slug',
        'position',
        'icon',
        'badge',
        'is_offer',
        'is_active',
        'spotlight_title',
        'spotlight_subtitle',
        'spotlight_image',
        'spotlight_link',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * The brand this shelf stands for, when it stands for one.
     *
     * The shop lists brands the way the trade does — "ASUS" is a shelf under
     * Brand PC, with its own page and its own URL — so a category and a brand
     * are the same thing seen from two sides, and this is the seam.
     *
     * Null for an ordinary shelf. Most are: 996 of the 1,140 third-level
     * shelves are product lines, not makers.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The shelves under this one, in the order the shop put them.
     *
     * Ordered on the relation rather than at each call site. Nothing ordered
     * categories at all before, and the menu, the footer and every picker
     * showed whatever the engine returned; putting it here means no future
     * caller can forget, the way each of those had.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->inMenuOrder();
    }

    /**
     * The shop's own order, with name as the tie-break.
     *
     * Two shelves can share a position — a fresh row defaults to 0 until it is
     * moved — and without a second key their order between themselves is
     * whatever the engine feels like, which is the thing this is fixing.
     */
    public function scopeInMenuOrder($query)
    {
        return $query->orderBy('position')->orderBy('name');
    }

    /** The questions this shelf asks about its products. */
    public function attributes()
    {
        return $this->belongsToMany(Attribute::class, 'attribute_category')
            ->withPivot('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * SSOT: Resolve all descendant category IDs (Parent + Children + Grandchildren)
     * for a given category slug or model instance.
     *
     * @return array<int>
     */
    public static function getDescendantIds(string|int|Category $identifier): array
    {
        if ($identifier instanceof Category) {
            $category = $identifier;
        } elseif (is_numeric($identifier)) {
            $category = self::with('children.children')->find($identifier);
        } else {
            $category = self::with('children.children')->where('slug', $identifier)->first();
        }

        if (! $category) {
            return [];
        }

        $ids = [$category->id];

        foreach ($category->children as $child) {
            $ids[] = $child->id;
            foreach ($child->children as $grandChild) {
                $ids[] = $grandChild->id;
            }
        }

        return array_unique($ids);
    }
}
