<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'logo_path', 'is_featured'];

    protected $casts = [
        'is_featured' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)->orderBy('name', 'asc');
    }
}
