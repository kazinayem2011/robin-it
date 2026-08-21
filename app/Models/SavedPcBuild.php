<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SavedPcBuild extends Model
{
    use HasFactory;

    protected $fillable = [
        'share_code',
        'user_id',
        'build_name',
        'components',
        'total_price',
        'customer_name',
        'customer_phone',
    ];

    protected $casts = [
        'components' => 'array',
        'total_price' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::creating(function ($build) {
            if (empty($build->share_code)) {
                $build->share_code = strtoupper(Str::random(8));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
