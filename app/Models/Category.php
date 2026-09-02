<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
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

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
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
