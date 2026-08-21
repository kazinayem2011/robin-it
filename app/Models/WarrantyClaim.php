<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_number',
        'user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'product_name',
        'serial_number',
        'invoice_number',
        'purchase_date',
        'issue_type',
        'issue_description',
        'dropoff_branch',
        'status',
        'diagnostic_notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
