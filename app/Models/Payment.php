<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'job_order_id',
        'amount',
        'payment_method',
        'source',
        'type',
        'payment_method_id',
        'reference',
        'recorded_by',
        'notes',
        'receipt_path',
        'rejected_at',
        'rejected_reason',
        'rejected_by',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'rejected_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $appends = ['status'];

    public const SOURCES = ['online', 'walk_in'];

    public const TYPES = ['deposit', 'partial', 'full', 'balance'];

    /** Stored values: Maya is kept as 'paymaya' (the value existing data already uses). */
    public const METHODS = ['cash', 'gcash', 'paymaya', 'bank_transfer', 'other'];

    /**
     * What a payment is for, worked out from where the order stands when it is made:
     * the first one is a deposit (or the full amount if it clears the order), later ones are
     * partial payments until one clears what is left — that one is the balance.
     */
    public static function inferType(JobOrder $job, float $amount): string
    {
        $pending = (float) $job->payments()->whereNull('verified_at')->whereNull('rejected_at')->sum('amount');
        $outstanding = max(0.0, (float) $job->balance - $pending);
        $clears = $amount >= $outstanding - 0.005;
        $hasPrior = $job->payments()->whereNull('rejected_at')->exists();

        return $hasPrior ? ($clears ? 'balance' : 'partial') : ($clears ? 'full' : 'deposit');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** 'rejected' | 'verified' | 'pending_verification' — never stored directly, always derived from the two timestamps so it can't drift out of sync with them. */
    public function getStatusAttribute(): string
    {
        if ($this->rejected_at) {
            return 'rejected';
        }

        return $this->verified_at ? 'verified' : 'pending_verification';
    }
}
