<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One GCash payment a shop sends SUTURA (the admin "Payments" ledger):
 * a new shop's plan (type new_shop, decided with its application), an
 * upgrade, a renewal, or a paid downgrade. Nothing changes on the store
 * until an admin approves it. Statuses: pending | approved ("Paid") | rejected.
 *
 * channel: receipt (screenshot, checked by an admin) | online (GCash checkout,
 * confirmed by the gateway, see App\Support\OnlinePlanPayments). Only online
 * payments can be refunded from SUTURA.
 */
class SubscriptionUpgradeRequest extends Model
{
    public const TYPES = ['new_shop', 'upgrade', 'renewal', 'downgrade'];

    protected $fillable = [
        'store_id', 'type', 'store_application_id', 'requested_by', 'plan_id', 'billing_cycle', 'quoted_price',
        'amount_sent', 'payment_method', 'payment_reference', 'reference_key', 'sender_name', 'sender_number',
        'paid_at_reported', 'payment_receipt_path', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
        'billing_period_start', 'billing_period_end', 'resubmission_of_id',
        'channel', 'gateway_invoice_id', 'gateway_checkout_url', 'gateway_status', 'gateway_paid_at', 'gateway_token',
        'refund_amount', 'refund_status', 'refund_reason', 'refund_gateway_id', 'refunded_at', 'refunded_by',
    ];

    // The raw storage path and the gateway's internal ids never leave the server.
    protected $hidden = ['payment_receipt_path', 'reference_key', 'gateway_token', 'gateway_invoice_id', 'refund_gateway_id'];

    protected $casts = [
        'quoted_price' => 'decimal:2',
        'amount_sent' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'paid_at_reported' => 'datetime',
        'billing_period_start' => 'datetime',
        'billing_period_end' => 'datetime',
        'gateway_paid_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    /**
     * Rows that belong in the Payments ledger and its totals: every receipt upload, and an
     * online GCash payment only once it is actually paid (an opened-but-abandoned checkout
     * is not a payment).
     */
    public function scopeInLedger(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('channel', '!=', 'online')->orWhere('gateway_status', 'paid'));
    }

    public function isOnline(): bool
    {
        return $this->channel === 'online';
    }

    /** Paid online and not refunded yet (a failed refund may be tried again). */
    public function refundable(): bool
    {
        return $this->isOnline() && $this->gateway_status === 'paid' && $this->gateway_invoice_id
            && in_array($this->refund_status, [null, 'failed'], true);
    }

    /** Money kept: what was paid minus a completed refund. */
    public function netAmount(): float
    {
        return (float) $this->quoted_price - ($this->refund_status === 'succeeded' ? (float) $this->refund_amount : 0.0);
    }

    /** "1234 5678 9" → "123456789": how references are compared. */
    public static function referenceKey(?string $reference): ?string
    {
        $key = strtoupper(preg_replace('/\s+/', '', (string) $reference));

        return $key === '' ? null : $key;
    }

    /** Is this GCash reference already on another pending or paid payment? */
    public static function referenceInUse(?string $key, ?int $exceptId = null): bool
    {
        return $key !== null && self::where('reference_key', $key)
            ->whereIn('status', ['pending', 'approved'])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * Things the admin should double-check (never blocks): the amount sent
     * isn't the plan price, the reference was used on another payment, or the
     * reported payment time is over 7 days before sending / in the future.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function warnings(): array
    {
        $out = [];
        if ($this->amount_sent !== null && abs((float) $this->amount_sent - (float) $this->quoted_price) > 0.009) {
            $out[] = ['code' => 'amount', 'message' => 'Amount sent (₱'.number_format((float) $this->amount_sent, 2).') is not the plan price (₱'.number_format((float) $this->quoted_price, 2).').'];
        }
        if ($this->reference_key && self::where('reference_key', $this->reference_key)->where('id', '!=', $this->id)->exists()) {
            $out[] = ['code' => 'duplicate', 'message' => 'This GCash reference number was also used on another payment.'];
        }
        if ($this->paid_at_reported && $this->created_at) {
            if ($this->paid_at_reported->lt($this->created_at->copy()->subDays(7))) {
                $out[] = ['code' => 'old', 'message' => 'The payment date is more than 7 days before it was sent.'];
            } elseif ($this->paid_at_reported->gt($this->created_at->copy()->addHour())) {
                $out[] = ['code' => 'future', 'message' => 'The payment date is after the time it was sent.'];
            }
        }

        return $out;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(StoreApplication::class, 'store_application_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class, 'payment_id')->orderBy('created_at')->orderBy('id');
    }

    /** Adds a timeline entry. */
    public function log(string $action, ?int $userId, ?string $note = null): void
    {
        PaymentLog::create(['payment_id' => $this->id, 'user_id' => $userId, 'action' => $action, 'note' => $note, 'created_at' => now()]);
    }
}
