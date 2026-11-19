<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    // Small, functional taxonomy driving which conditional Job Order fields
    // apply (see JobOrderController/JobCreateForm) — distinct from the
    // free-text `category`/`tags` fields, which stay as rich marketing labels.
    public const TYPE_CUSTOM_TAILORING = 'custom_tailoring';

    public const TYPE_BULK_SUBLIMATION = 'bulk_sublimation';

    public const TYPE_FASHION_BRIDAL = 'fashion_bridal';

    public const TYPE_ALTERATION_REPAIR = 'alteration_repair';

    public const SERVICE_TYPES = [
        self::TYPE_CUSTOM_TAILORING,
        self::TYPE_BULK_SUBLIMATION,
        self::TYPE_FASHION_BRIDAL,
        self::TYPE_ALTERATION_REPAIR,
    ];

    protected $fillable = [
        'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
        'store_id', 'name', 'description', 'category', 'categories', 'department', 'service_type', 'service_types', 'tags',
        // service_category/service_leaf_type: the new canonical Services.md
        // taxonomy (Custom Tailoring > Bespoke Tailoring, etc) — additive to,
        // not a replacement for, the free-text category/categories above and
        // the operational service_type/service_types (TYPE_CUSTOM_TAILORING
        // etc, drives Job Order conditional fields) below. See
        // App\Support\CanonicalTaxonomy.
        'service_category', 'showcase_category', 'service_leaf_type',
        'base_price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
        'estimated_days', 'estimated_days_max', 'min_order_qty', 'is_active', 'custom_fields', 'roster_fields', 'image_url',
        'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
        // Per-piece amount off when the customer brings their own fabric (null = none set).
        'own_fabric_deduction',
        // Set when the shop's plan allows fewer services (App\Support\PlanLimits); is_active is then false.
        'plan_locked_at',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'min_order_qty' => 'integer',
        'own_fabric_deduction' => 'decimal:2',
        'is_active' => 'boolean',
        'plan_locked_at' => 'datetime',
        'custom_fields' => 'array',
        'roster_fields' => 'array',
        'tags' => 'array',
        'size_chart_columns' => 'array',
        'size_chart_rows' => 'array',
        'categories' => 'array',
        'service_types' => 'array',
    ];

    /**
     * A service can now carry more than one functional type at once (e.g. a
     * group that's both Bulk/Sublimation and Alterations) — every conditional
     * Job Order rule for each type it has still applies (union, not one-of).
     */
    public function hasType(string $type): bool
    {
        return in_array($type, $this->service_types ?? [], true);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ServicePricing::class, 'service_id');
    }

    public function packages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ServicePackage::class, 'service_package_items', 'service_id', 'service_package_id');
    }

    /**
     * A combo needs at least 2 services that customers can actually order. After this service is
     * deleted or paused, pause every active combo that no longer has that, and return their names
     * so the owner can be told. Never re-activates anything — that stays the owner's call.
     */
    public function pausePackagesLeftTooSmall(): array
    {
        $paused = [];
        foreach ($this->packages()->where('is_active', true)->get() as $package) {
            if ($package->services()->where('services.is_active', true)->count() < 2) {
                $package->update(['is_active' => false]);
                $paused[] = $package->name;
            }
        }

        return $paused;
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceReview::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(ServiceSave::class);
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    /**
     * The price to actually suggest right now, respecting the optional sale
     * window.
     */
    public function effectivePrice(): float
    {
        if ($this->sale_price === null || (float) $this->sale_price >= (float) $this->base_price) {
            return (float) $this->base_price;
        }

        // sale_starts_at/sale_ends_at only ever carry a bare date (no time)
        // from the Set Sale Price form. The app runs on UTC, but the store is
        // in the Philippines (UTC+8) — comparing the raw 'datetime' cast
        // (which anchors that bare date at UTC midnight) makes a sale
        // advertised as "through Aug 10" actually expire at 8am Manila time
        // on Aug 10, and a sale "starting Aug 5" not kick in until 8am
        // Manila time instead of the start of that day. Re-anchor to the
        // store's local day boundaries instead.
        $now = now();
        if ($this->sale_starts_at) {
            $startsAt = Carbon::parse($this->sale_starts_at->toDateString(), 'Asia/Manila')->startOfDay();
            if ($now->lt($startsAt)) {
                return (float) $this->base_price;
            }
        }
        if ($this->sale_ends_at) {
            $endsAt = Carbon::parse($this->sale_ends_at->toDateString(), 'Asia/Manila')->endOfDay();
            if ($now->gt($endsAt)) {
                return (float) $this->base_price;
            }
        }

        return (float) $this->sale_price;
    }
}
