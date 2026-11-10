<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'slug', 'store_code', 'description', 'address', 'landmark',
        'city', 'province', 'postal_code', 'phone', 'email',
        'logo_path', 'banner_path', 'gallery_images', 'status', 'rejection_reason', 'approved_at', 'approved_by',
        'booking_policy', 'booking_questions', 'max_appointments_per_day', 'latitude', 'longitude', 'social_links',
        'business_type', 'operating_hours',
        'fitting_fee', 'fitting_limit', 'fitting_limit_policy', 'fitting_visit_days', 'fitting_message', 'repair_requires_downpayment',
        'sms_mode', 'sms_events', 'default_measurement_requirement', 'default_fitting_requirement', 'default_payment_policy', 'default_payment_percent',
        'specializations', 'custom_services', 'declared_branches', 'is_featured', 'is_hidden',
        'gcash_number', 'gcash_account_name', 'bank_name', 'bank_account_number', 'bank_account_name',
        'gcash_qr_path', 'bank_qr_path',
    ];

    /**
     * store_code is confidential — used by owners, branch managers, and staff
     * for tracking prefixes and internal identification. Never exposed to
     * public storefront visitors or customer browsing endpoints.
     */
    protected $hidden = [
        'store_code',
    ];

    protected static function booted(): void
    {
        static::creating(function (Store $store) {
            if (empty($store->store_code) && ! empty($store->name)) {
                $letters = preg_replace('/[^A-Za-z]/', '', $store->name);
                $code = strtoupper(substr($letters, 0, 4));
                if (strlen($code) < 4) {
                    $code = str_pad($code, 4, 'X');
                }
                $store->store_code = $code;
            }
        });
    }

    // admin_hidden_at/admin_hidden_reason are deliberately NOT fillable —
    // only Admin\ModerationController may set them, via forceFill().
    protected $casts = [
        'approved_at' => 'datetime',
        'admin_hidden_at' => 'datetime',
        'booking_questions' => 'array',
        'social_links' => 'array',
        'gallery_images' => 'array',
        'operating_hours' => 'array',
        'specializations' => 'array',
        'custom_services' => 'array',
        'declared_branches' => 'array',
        'is_featured' => 'boolean',
        'is_hidden' => 'boolean',
        'fitting_fee' => 'float',
        'fitting_visit_days' => 'integer',
        'repair_requires_downpayment' => 'boolean',
        'sms_events' => 'array',
        'max_appointments_per_day' => 'integer',
    ];

    protected $appends = [
        'active_special_hours',
    ];

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === 'slug' || $field === null) {
            return $this->where('slug', $value)
                ->when(is_numeric($value), fn ($q) => $q->orWhere('id', (int) $value))
                ->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(StoreSubscription::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(StoreBranch::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    /** The owner's verification application (null for stores created before it existed). */
    public function application(): HasOne
    {
        return $this->hasOne(StoreApplication::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(StoreSubscription::class)->latestOfMany();
    }

    /**
     * Stores currently on a paid-up Premium plan: an active or trial Premium
     * subscription that hasn't run out. ends_at is checked too, because a
     * lapsed row keeps its status until the ExpireSubscriptions command runs.
     */
    public function scopePremiumActive($query)
    {
        return $query->whereHas('subscriptions', function ($sq) {
            $sq->whereIn('status', ['active', 'trial'])
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->whereHas('plan', fn ($pq) => $pq->where('slug', 'premium'));
        });
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function customers(): BelongsToMany
    {
        // withPivot('notes') — store-specific customer notes deliberately live
        // here, not on the User row: a note like "prefers slim fit" only
        // makes sense in the context of the specific store that wrote it, and
        // suki_tag (which does live on User) already shows the pitfall of a
        // per-customer field that's meant to be store-specific but isn't.
        return $this->belongsToMany(User::class, 'store_customers')->withPivot('notes')->withTimestamps();
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StoreReview::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(StorePost::class);
    }

    public function specialHours(): HasMany
    {
        return $this->hasMany(StoreSpecialHour::class);
    }

    public function getActiveSpecialHoursAttribute()
    {
        // Asia/Manila, not the app's UTC default — the app-wide timezone is
        // UTC, so comparing against a bare now()->toDateString() could be up
        // to 8 hours off from the store's actual local day boundary.
        $today = now('Asia/Manila')->toDateString();

        return $this->specialHours()
            // This top-of-storefront banner has no branch context to filter
            // by (it renders before a location is picked), so it only ever
            // shows store-wide announcements — a branch-specific closure
            // shows on that branch's own card instead (see branches()).
            ->whereNull('store_branch_id')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            // Most-recently-created wins when two ranges overlap (e.g. an
            // urgent closure notice added on top of a standing promo) —
            // otherwise which one displays is undefined DB row order.
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Title of the announced closure covering $date, or null if the store is
     * open. Shared by AppointmentController (blocking a new booking/
     * reschedule) and JobOrderController (picking a real open day for the
     * auto-generated fitting appointment) instead of duplicating the same
     * query in both — this data previously only ever reached the frontend
     * for display, nothing on the backend ever checked it.
     */
    /**
     * $branchId null matches store-wide closures only. Passing the branch a
     * booking/appointment actually belongs to also matches a closure
     * announced for just that one branch (store_branch_id set) — e.g.
     * "Lanang closed for renovation" no longer has to close Main/Matina too.
     */
    public function closureTitleOn(Carbon $date, ?int $branchId = null): ?string
    {
        return $this->specialHours()
            ->where('is_closed', true)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->when(
                $branchId,
                fn ($q) => $q->where(fn ($q2) => $q2->whereNull('store_branch_id')->orWhere('store_branch_id', $branchId)),
                fn ($q) => $q->whereNull('store_branch_id')
            )
            ->value('title');
    }
}
