<?php

namespace App\Models;

use App\Notifications\StoreActivityNotification;
use App\Support\OrderRequirements;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;

class JobOrder extends Model
{
    use SoftDeletes;

    /**
     * Single source of truth for the 3-Phase Tailoring Tracker pipeline —
     * unified with the Multi-Stage Staff Assignment stages (design/
     * pattern_making/cutting/sewing/qc_ironing) so the customer-facing
     * timeline and the internal staff-assignment tracking describe the same
     * process instead of two different vocabularies.
     *
     * - 'mass_cutting_printing' is the Bulk Order Override: a job with a
     *   Team Roster / Size Sheet (custom_order_data.team_roster) skips
     *   'pattern_making' and goes straight here instead.
     * - 'ready_for_fitting' auto-creates a Fitting appointment for the
     *   customer (see JobOrderController@update).
     * - 'final_adjustments' is the revert target if a fitting reveals issues
     *   — from there the job either goes back to 'sewing' for rework or
     *   forward to 'qc_ironing' once resolved.
     */
    public const STATUSES = [
        'pending', 'production', 'quality_check', 'ready_for_fitting', 'adjustments', 'ready_for_pickup',
        'completed', 'cancelled', 'rejected', 'on_hold',
        // Granular legacy stages preserved for full backward compatibility
        'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing',
        'final_adjustments', 'qc_ironing',
        'queued', 'in_repair', 'qc_check',
    ];

    /**
     * Assignable Multi-Stage Staff Assignment stages. Deliberately does NOT
     * include 'fitting' — fittings happen front-of-house with the master
     * cutter/owner directly, not a backroom production stage — nor
     * 'mass_cutting_printing', 'final_adjustments', 'ready_for_pickup' etc.,
     * which aren't separately staffed roles.
     */
    public const STAFF_STAGES = ['design', 'pattern_making', 'cutting', 'sewing', 'qc_ironing'];

    /**
     * The "No DP, No Layout, No Cut" golden rule: a job cannot enter any of
     * these production stages until a 50% downpayment has been logged.
     * 'pending' and 'design' are deliberately exempt — no fabric or material
     * is committed yet at those stages, only once pattern-making/cutting
     * actually starts. Enforced in JobOrderController@update; the Kanban
     * board mirrors this same array so the UI and the API never drift apart.
     *
     * 'ready_for_pickup' is included even though it comes after every other
     * gated stage — the status dropdown lets a job jump directly from
     * 'pending'/'design' to ANY stage, not just the next one in sequence, so
     * without this a job with $0 paid could skip straight to "Ready for
     * Pickup" and sit there having never actually passed the DP gate.
     */
    public const STAGES_REQUIRING_DOWNPAYMENT = [
        'production', 'quality_check', 'adjustments',
        'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing',
        'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup',
        // Repair Override pipeline — same "no material committed until a
        // downpayment is logged" rule applies once a repair actually starts
        // (not at 'pending'/'queued', mirroring 'pending'/'design' being
        // exempt above). docs/REPAIR-WORKFLOW.md §5.
        'in_repair', 'qc_check',
    ];

    /**
     * Who provided the fabric/garment being worked on — the digital equivalent
     * of a store physically taping a fabric swatch onto a paper logbook entry so
     * staff don't confuse a customer's own material with store stock. Applies
     * across service types, not just alterations (which already separately
     * track pre-existing damage on an existing garment via custom_order_data).
     */
    public const MATERIAL_SOURCES = ['store_supplied', 'customer_supplied'];

    /**
     * Factual condition of a customer-supplied material, set by the
     * authorized store-side role after physically assessing it — meaningful
     * only when material_source = 'customer_supplied'. The system records
     * the fact only; liability/compensation decisions are never derived from
     * this value. See docs/EMERGENCY-WORKFLOW.md §7.
     */
    public const CUSTOMER_MATERIAL_STATUSES = ['safe', 'damaged', 'lost', 'returned'];

    /**
     * Categorizes why a job order was cancelled. `forfeited_deposit_abandoned`
     * is the one with real financial-reporting consequences (see
     * AnalyticsController) — the customer went uncontactable after fabric was
     * already cut, and per store policy the deposit already collected is kept,
     * not refunded. The other three carry no reversal or reporting logic.
     */
    public const CANCELLATION_REASONS = [
        'customer_request', 'store_unable_to_fulfill', 'forfeited_deposit_abandoned', 'other',
    ];

    // Every view of an order (owner, staff, customer, public tracker) names the combo it's for.
    protected $with = ['servicePackage:id,name,bundle_price,service_category', 'servicePackage.services:id,name'];

    protected $fillable = [
        'order_number', 'tracking_code', 'intake_channel', 'fulfillment_type', 'store_id', 'store_branch_id', 'customer_id', 'service_id', 'service_package_id',
        'catalog_item_id', 'assigned_staff_id', 'assigned_to', 'measurement_id', 'quantity', 'total_amount',
        'balance', 'payment_status', 'status', 'due_date', 'notes',
        'custom_order_data',
        'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
        'is_rush', 'rush_fee', 'completion_photo_url',
        'reference_images', 'reference_link', 'material_source', 'garment_category',
        'discount_amount', 'rejection_reason', 'cancellation_reason', 'hold_reason',
        'estimated_ready_at', 'customer_material_status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        // Explicit Y-m-d — a bare 'date' cast still round-trips through
        // Eloquent's full datetime format on save, silently writing a
        // "00:00:00" time component into a column declared as a pure DATE.
        'due_date' => 'date:Y-m-d',
        // Time-of-day granularity due_date deliberately doesn't have — see
        // docs/REPAIR-WORKFLOW.md §6. A real 'datetime' cast here on purpose,
        // unlike due_date above.
        'estimated_ready_at' => 'datetime',
        'custom_order_data' => 'array',
        'reference_images' => 'array',
        'is_rush' => 'boolean',
        'rush_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'first_adjustment_at' => 'datetime',
        'completed_at' => 'datetime',
        'adjustment_count' => 'integer',
        'progress_photos' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /** Set when the order is a whole combo package (one job order for the set). */
    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function staffStages(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'job_order_staff', 'job_order_id', 'user_id')
            ->using(JobOrderStaff::class)
            ->withPivot('stage', 'assigned_at', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Bulk Order Override signal: a Team Roster / Size Sheet was submitted,
     * or the linked service is itself a bulk-sublimation service. Used to
     * decide whether the job's next production stage after 'design' should
     * be 'mass_cutting_printing' instead of 'pattern_making'.
     */
    public function isBulkOrder(): bool
    {
        if (! empty($this->custom_order_data['team_roster'] ?? null)) {
            return true;
        }

        if (! empty($this->custom_order_data['size_breakdown'] ?? null)) {
            return true;
        }

        return $this->service?->hasType(Service::TYPE_BULK_SUBLIMATION) ?? false;
    }

    /**
     * Repair Override signal, mirroring isBulkOrder() above — a job whose
     * garment_category is 'alteration_repair', or whose linked Service
     * carries the alteration_repair type, is a candidate for the short
     * repair pipeline (pending -> queued -> in_repair -> qc_check ->
     * ready_for_pickup -> completed) instead of the full custom-tailoring
     * one. Read-only signal only — this model does not itself drive any
     * automatic status transition; which stage a repair job is actually in
     * remains a Staff/Owner operational decision (docs/REPAIR-WORKFLOW.md §5,
     * docs/STAFF-WORKFLOW.md §8, cross-role dependency).
     */
    /**
     * Whether moving into $status needs the 50% downpayment first. Repairs
     * only need it when the shop opted in (repair_requires_downpayment) —
     * many shops charge repairs at pickup. Everything else always does.
     */
    public function requiresDownpaymentFor(string $status): bool
    {
        if (! in_array($status, self::STAGES_REQUIRING_DOWNPAYMENT, true)) {
            return false;
        }

        if ($this->isRepairOnly() && ! $this->store?->repair_requires_downpayment) {
            return false;
        }

        return $this->requiredDepositFraction() > 0;
    }

    /** Share of the amount due that must be paid before production (snapshotted policy). */
    public function requiredDepositFraction(): float
    {
        return OrderRequirements::depositFraction($this->payment_policy ?? 'deposit', (int) ($this->payment_policy_percent ?? 50));
    }

    public function isRepairOnly(): bool
    {
        if ($this->garment_category === 'alteration_repair') {
            return true;
        }

        return $this->service?->hasType(Service::TYPE_ALTERATION_REPAIR) ?? false;
    }

    /**
     * Notifications referencing a job order (NewJobOrderNotification,
     * JobStatusUpdatedNotification, StoreActivityNotification, etc.) are
     * plain JSON blobs with no foreign key to this table — deleting the job
     * used to leave them behind as dead links that 404 the moment someone
     * clicks through. Cleaned up here instead, on both soft and force delete.
     */
    protected static function booted(): void
    {
        static::saving(function (JobOrder $job) {
            if ($job->assigned_staff_id && ! $job->assigned_to) {
                $job->assigned_to = $job->assigned_staff_id;
            } elseif ($job->assigned_to && ! $job->assigned_staff_id) {
                $job->assigned_staff_id = $job->assigned_to;
            }
        });

        // Snapshot the requirements at creation (design -> linked service -> store;
        // combo -> own else store). Every creation path goes through here.
        static::creating(function (JobOrder $job) {
            $store = $job->store_id ? Store::find($job->store_id) : null;
            if (! $store) {
                return;
            }
            $chain = [];
            if ($job->service_package_id) {
                $chain = [ServicePackage::find($job->service_package_id)];
            } else {
                $item = $job->catalog_item_id ? CatalogItem::find($job->catalog_item_id) : null;
                $chain = [$item, $item?->service_id ? Service::find($item->service_id) : null];
                if (! $item && $job->service_id) {
                    $chain[] = Service::find($job->service_id);
                }
            }
            $resolved = OrderRequirements::resolve($store, $chain);
            foreach ($resolved as $field => $value) {
                if (! array_key_exists($field, $job->getAttributes())) {
                    $job->{$field} = $value;
                }
            }
        });

        // Tell the branch's staff a new order has landed (their "new order assignment"):
        // everyone on the floor at that branch, never the person who made it.
        static::created(function (JobOrder $job) {
            $staff = User::whereHas('staffProfile', function ($q) use ($job) {
                $q->where('store_id', $job->store_id)->where('is_branch_manager', false)
                    ->when($job->store_branch_id, fn ($q2) => $q2->where('store_branch_id', $job->store_branch_id));
            })->where('id', '!=', Auth::id() ?? 0)->get();

            foreach ($staff as $member) {
                $member->notify(new StoreActivityNotification(
                    'job_new_for_branch',
                    'New Order',
                    "Order {$job->order_number} was added".($job->due_date ? ' — due '.\Illuminate\Support\Carbon::parse($job->due_date)->format('M j') : '').'.',
                    '/dashboard/jobs/'.$job->id,
                    ['job_order_id' => $job->id]
                ));
            }
        });

        static::deleting(function (self $jobOrder) {
            DatabaseNotification::whereJsonContains('data->job_order_id', $jobOrder->id)->delete();
        });
    }
}
