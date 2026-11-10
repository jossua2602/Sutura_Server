<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's after-sale claim on a completed order: repair/alteration or refund.
 * See the create_order_claims_table migration for the flow.
 */
class OrderClaim extends Model
{
    /** Days after completion (or after a warranty repair is completed) a claim can be filed. */
    public const WINDOW_DAYS = 3;

    public const TYPES = ['repair', 'refund'];

    public const REASONS = ['poor_stitching', 'wrong_fit', 'damaged', 'other'];

    public const REFUND_METHODS = ['cash', 'gcash'];

    /** Still waiting on the shop or in progress — only one of these per order at a time. */
    public const OPEN_STATUSES = ['submitted', 'repair_offered', 'repairing', 'awaiting_return'];

    protected $fillable = [
        'store_id', 'job_order_id', 'customer_id', 'filed_by_id',
        'type', 'reason', 'description', 'photos', 'status',
        'decided_by_id', 'decided_at', 'decline_reason', 'escalated_ticket_id', 'repair_job_order_id',
        'offered_due_date', 'offered_staff_id', 'offer_note', 'repair_offered_at', 'offer_declined_at',
        'garment_returned_at', 'refund_amount', 'refund_method', 'refund_reference', 'refund_proof_path', 'refunded_at',
        'resolved_at',
    ];

    protected $casts = [
        'photos' => 'array',
        'decided_at' => 'datetime',
        'garment_returned_at' => 'datetime',
        'refunded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'offered_due_date' => 'date:Y-m-d',
        'repair_offered_at' => 'datetime',
        'offer_declined_at' => 'datetime',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function repairJobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class, 'repair_job_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * When the current claim window opened: the order's completion, or the
     * completion of its latest warranty repair (a repair gets its own 3 days).
     */
    public static function windowStartFor(JobOrder $order): ?Carbon
    {
        if ($order->status !== 'completed' || ! $order->completed_at) {
            return null;
        }
        $repairDone = JobOrder::whereIn('id', self::where('job_order_id', $order->id)->whereNotNull('repair_job_order_id')->pluck('repair_job_order_id'))
            ->where('status', 'completed')->max('completed_at');

        $start = $order->completed_at->copy();
        if ($repairDone && Carbon::parse($repairDone)->gt($start)) {
            $start = Carbon::parse($repairDone);
        }

        return $start;
    }

    /**
     * Whether a new claim can be filed now, and why not. One claim per window:
     * an open, declined or refunded claim in the current window blocks another
     * (a declined customer escalates to SUTURA support instead).
     *
     * @return array{can_file: bool, window_ends_at: ?string, reason: ?string}
     */
    public static function eligibility(JobOrder $order): array
    {
        $start = self::windowStartFor($order);
        if (! $start) {
            return ['can_file' => false, 'window_ends_at' => null, 'reason' => 'Problems can be reported once the order is completed.'];
        }
        $ends = $start->copy()->addDays(self::WINDOW_DAYS);
        $claims = self::where('job_order_id', $order->id);

        if ((clone $claims)->where('status', 'refunded')->exists()) {
            return ['can_file' => false, 'window_ends_at' => $ends->toIso8601String(), 'reason' => 'This order has already been refunded.'];
        }
        if ((clone $claims)->whereIn('status', self::OPEN_STATUSES)->exists()) {
            return ['can_file' => false, 'window_ends_at' => $ends->toIso8601String(), 'reason' => 'A claim on this order is still open.'];
        }
        if ((clone $claims)->where('created_at', '>=', $start)->exists()) {
            return ['can_file' => false, 'window_ends_at' => $ends->toIso8601String(), 'reason' => 'A claim was already filed for this order.'];
        }
        if (now()->gt($ends)) {
            return ['can_file' => false, 'window_ends_at' => $ends->toIso8601String(), 'reason' => 'The '.self::WINDOW_DAYS.'-day window to report a problem has passed.'];
        }

        return ['can_file' => true, 'window_ends_at' => $ends->toIso8601String(), 'reason' => null];
    }

    /** What the customer paid for the order — the most a refund can be. */
    public static function paidAmount(JobOrder $order): float
    {
        return max(0, round((float) $order->total_amount - (float) $order->balance - (float) $order->discount_amount, 2));
    }

    public function toClientArray(bool $forShop = false): array
    {
        $repair = $this->repairJobOrder;

        return [
            'id' => $this->id,
            'job_order_id' => $this->job_order_id,
            'type' => $this->type,
            'reason' => $this->reason,
            'description' => $this->description,
            'photos' => $this->photos ?? [],
            'status' => $this->status,
            'decline_reason' => $this->decline_reason,
            'escalated_ticket_id' => $this->escalated_ticket_id,
            'repair_offer' => $this->repair_offered_at ? [
                'due_date' => $this->offered_due_date?->toDateString(),
                'note' => $this->offer_note,
                'offered_at' => $this->repair_offered_at,
                'declined_at' => $this->offer_declined_at,
            ] : null,
            'decided_at' => $this->decided_at,
            'repair_order' => $repair ? [
                'id' => $repair->id,
                'order_number' => $repair->order_number,
                'tracking_code' => $repair->tracking_code,
                'status' => $repair->status,
                'due_date' => $repair->due_date,
            ] : null,
            'garment_returned_at' => $this->garment_returned_at,
            'refund_amount' => $this->refund_amount !== null ? (float) $this->refund_amount : null,
            'refund_method' => $this->refund_method,
            'refund_reference' => $this->refund_reference,
            'refund_proof_path' => $this->refund_proof_path,
            'refunded_at' => $this->refunded_at,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'filed_by' => $forShop ? $this->filedBy?->name : null,
            'decided_by' => $forShop ? $this->decidedBy?->name : null,
        ];
    }
}
