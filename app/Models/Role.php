<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Model;

/**
 * A job in the shop, and what it covers.
 */
class Role extends Model
{
    protected $fillable = ['key', 'label', 'description', 'abilities', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The abilities every request reads are cached; a change here has to
        // be visible on the next one.
        static::saved(fn () => Roles::forget());
        static::deleted(fn () => Roles::forget());
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'key');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
}
