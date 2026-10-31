<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'price_monthly', 'price_yearly',
        'max_staff', 'max_branches', 'max_services', 'max_appointments_per_month', 'features', 'is_active',
    ];

    /**
     * How many Branch Shops a store on this plan may add on top of its Main
     * Shop (-1 = unlimited, 0 = main shop only). The Main Shop never counts.
     * Set on the admin Plans page; a plan saved before that existed falls
     * back to the old rule (Premium unlimited, everything else main only).
     */
    public function branchLimit(): int
    {
        if ($this->max_branches !== null) {
            return (int) $this->max_branches;
        }

        return str_contains((string) $this->slug, 'premium') ? -1 : 0;
    }

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['display_features'];

    /**
     * What plan cards show: the staff and branch limits written out from
     * max_staff / max_branches (so an admin edit on the Plans page shows up
     * everywhere at once), then the admin's own feature lines. `features`
     * itself stays exactly as typed.
     */
    public function getDisplayFeaturesAttribute(): array
    {
        $features = array_values(array_filter(
            $this->features ?? [],
            // A hand-typed staff line would contradict the generated one.
            fn ($f) => ! preg_match('/^(staff accounts|up to \d+ (staff|branch)|unlimited (staff|branch)|\d+ branch|main shop)/i', (string) $f),
        ));

        $staff = match (true) {
            $this->max_staff === null => null,
            (int) $this->max_staff === -1 => 'Unlimited staff accounts',
            (int) $this->max_staff === 0 => null,
            default => 'Up to '.(int) $this->max_staff.' staff '.((int) $this->max_staff === 1 ? 'account' : 'accounts'),
        };
        $branchLimit = $this->slug !== null || $this->max_branches !== null ? $this->branchLimit() : null;
        $branches = match (true) {
            $branchLimit === null => null,
            $branchLimit === -1 => 'Unlimited branch shops',
            $branchLimit === 0 => 'Main shop only',
            $branchLimit === 1 => 'Main shop + 1 branch shop',
            default => "Main shop + up to {$branchLimit} branch shops",
        };

        return array_values(array_filter([$staff, $branches, ...$features]));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(StoreSubscription::class, 'plan_id');
    }
}
