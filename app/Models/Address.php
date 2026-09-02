<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = [
        'user_id', 'type', 'name', 'phone',
        'division', 'district', 'city', 'address', 'zone', 'delivery_zone',
        'street_address', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One-line address for order summaries and the checkout address picker.
     */
    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->address ?: $this->street_address,
            $this->city,
            $this->district,
            $this->division,
        ])->filter()->implode(', ');
    }
}
