<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    // Single source of truth for valid role values — referenced by both
    // StoreStaffRequest/UpdateStaffRequest (for `role` and `additional_roles.*`)
    // and the frontend's role dropdown options. Includes a few values that
    // only ever showed up in seed data historically (sublimation_specialist,
    // senior_designer, cutter_sewer) so those existing profiles are actually
    // editable instead of showing a blank/unmatched dropdown.
    //
    // 'subcontractor' was removed (2026-09-28) — it implied the shop could
    // hire out work to a partner store, but that's exactly the outsourcing
    // concept JobOrder::is_outsourced/partner_store_name/outsourcing_cost
    // were already removed for (maps to no thesis objective, edges into
    // logistics/expense tracking — see this repo's CLAUDE.md "Explicitly OUT
    // of scope"). Offering it as a staff role contradicted that removal.
    public const ROLES = [
        'head_tailor', 'tailor', 'cutter', 'seamstress', 'assistant',
        'receptionist', 'quality_control', 'designer',
        'pattern_maker', 'sublimation_specialist', 'senior_designer', 'cutter_sewer',
    ];

    protected $fillable = [
        'user_id', 'store_id', 'store_branch_id', 'role', 'additional_roles', 'specialization',
        'bio', 'is_active', 'hired_at', 'is_branch_manager', 'is_available',
        // Set when the shop's plan allows fewer staff (App\Support\PlanLimits): the account is read-only.
        'plan_locked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'plan_locked_at' => 'datetime',
        // Was missing from both $fillable and here — ProfileController's
        // existing toggleAvailability() endpoint has been silently no-op'ing
        // on every call since the column was added, same silent-drop bug
        // class as User.profile_picture (see StaffController's bio fix).
        'is_available' => 'boolean',
        // Explicit Y-m-d — a bare 'date' cast still round-trips through
        // Eloquent's full datetime format on save, silently writing a
        // "00:00:00" time component into a column declared as a pure DATE.
        'hired_at' => 'date:Y-m-d',
        'specialization' => 'array',
        'additional_roles' => 'array',
        'is_branch_manager' => 'boolean',
    ];

    protected $appends = ['all_roles'];

    /** Primary role first (rank 1), then additional roles in rank order. */
    public function getAllRolesAttribute(): array
    {
        return array_values(array_filter(array_merge([$this->role], $this->additional_roles ?? [])));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }
}
