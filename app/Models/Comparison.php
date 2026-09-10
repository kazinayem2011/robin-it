<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comparison extends Model
{
    /**
     * How many will fit across the comparison table before it stops being
     * readable on a phone.
     */
    public const MAX_ITEMS = 4;

    protected $fillable = ['user_id', 'session_id', 'product_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
