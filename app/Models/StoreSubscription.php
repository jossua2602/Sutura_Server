<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSubscription extends Model
{
    protected $fillable = [
        'store_id', 'plan_id', 'status', 'billing_cycle', 'starts_at',
        'ends_at', 'trial_ends_at', 'cancelled_at',
        // A downgrade waiting for this period to end (paid one: scheduled_payment_id).
        'scheduled_plan_id', 'scheduled_billing_cycle', 'scheduled_payment_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expiry_reminder_sent_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'scheduled_plan_id');
    }

    public function scheduledPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionUpgradeRequest::class, 'scheduled_payment_id');
    }
}
