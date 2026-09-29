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
        'product_serial_id',
        'replacement_serial_id',
        'invoice_number',
        'purchase_date',
        'issue_type',
        'issue_description',
        'dropoff_branch',
        'status',
        'ready_at',
        'closed_at',
        'diagnostic_notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'ready_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $appends = ['status_label'];

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

    /*
     * What people read. The screens said "Under Diagnostic Bench Test" and
     * "READY_FOR_PICKUP"; the stored keys are unchanged.
     */
    public const LABELS = [
        'received' => 'Received',
        'diagnosing' => 'Checking',
        'repairing' => 'Repairing',
        'ready_for_pickup' => 'Ready for pickup',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
    ];

    /** Nothing moves a claim on from these. */
    public const FINAL = ['completed', 'rejected'];

    /*
     * When it was ready to collect, and when it was finished — what the
     * warranty report times a repair by. Set here, so no way of moving a
     * claim can forget them.
     */
    protected static function booted(): void
    {
        static::saving(function (WarrantyClaim $claim) {
            if (in_array($claim->status, ['ready_for_pickup', 'completed'], true) && $claim->ready_at === null) {
                $claim->ready_at = now();
            }
            if (in_array($claim->status, self::FINAL, true) && $claim->closed_at === null) {
                $claim->closed_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The shop's own unit this claim is about, when the serial is one it knows. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    /** The unit handed over in its place, if it was replaced. */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'replacement_serial_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /**
     * Where a claim may go from here: onward, never back.
     *
     * Rejected is open until the claim is finished — a unit can turn out to be
     * physically damaged at any stage — and nothing leaves Completed or
     * Rejected.
     *
     * @return list<string>
     */
    public function nextStatuses(): array
    {
        if ($this->isFinal()) {
            return [$this->status];
        }

        $at = array_search($this->status, self::STATUSES, true);
        $onward = array_slice(array_diff(self::STATUSES, ['rejected']), (int) $at);

        return [...array_values($onward), 'rejected'];
    }
}
