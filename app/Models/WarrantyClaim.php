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

    /**
     * RMA lifecycle states, in the order a claim moves through them.
     *
     * The list lived as a magic string inside the admin controller's validation
     * rule, so nothing else could check a status against it.
     */
    public const STATUSES = [
        'received',
        'diagnosing',
        'repairing',
        'ready_for_pickup',
        'completed',
        'rejected',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
