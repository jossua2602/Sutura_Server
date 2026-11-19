<?php

namespace App\Support;

use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\SubscriptionPlan;
use App\Notifications\StoreActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Lock, never delete: when a shop is on a plan that allows fewer branch
 * shops, staff accounts or services than it has, the ones above the limit
 * are locked (plan_locked_at). The oldest stay open; anything the owner
 * already chose to keep stays open first. Moving to a bigger plan unlocks
 * them again, oldest first.
 *
 *  - Locked branch shop: status 'inactive' (off the map, search, booking).
 *  - Locked service: is_active false (not bookable or orderable).
 *  - Locked staff: can still sign in, read-only (CheckRole refuses changes).
 *
 * Only what the plan locked is ever unlocked by it: a branch or service the
 * owner switched off themselves has no plan_locked_at and is left alone.
 */
class PlanLimits
{
    public const TYPES = ['branch', 'staff', 'service'];

    private const LABELS = [
        'branch' => ['branch shop', 'branch shops'],
        'staff' => ['staff account', 'staff accounts'],
        'service' => ['service', 'services'],
    ];

    /** The plan's limit for a type (-1 = unlimited). */
    public static function limit(SubscriptionPlan $plan, string $type): int
    {
        return match ($type) {
            'branch' => $plan->branchLimit(),
            'staff' => (int) $plan->max_staff,
            'service' => (int) $plan->max_services,
        };
    }

    /**
     * The rows that count toward a type's limit, in keep order: open ones
     * before locked ones (keeps the owner's choice), then oldest first.
     * Branch shops never include the main shop; a branch or service the owner
     * switched off themselves doesn't count.
     */
    public static function candidates(Store $store, string $type): Collection
    {
        $query = match ($type) {
            'branch' => StoreBranch::where('store_id', $store->id)->where('is_main', false)
                ->where(fn (Builder $q) => $q->where('status', 'active')->orWhereNotNull('plan_locked_at')),
            'staff' => StaffProfile::where('store_id', $store->id),
            'service' => Service::where('store_id', $store->id)
                ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereNotNull('plan_locked_at')),
        };
        if ($type === 'staff') {
            // Branch managers are kept before plain staff.
            $query->orderByDesc('is_branch_manager');
        }

        return $query->orderByRaw('plan_locked_at IS NOT NULL')->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Brings the shop in line with its plan: locks what is above each limit,
     * unlocks locked ones that now fit. Returns ['locked' => [type => n], 'unlocked' => [type => n]].
     */
    public static function enforce(Store $store, ?SubscriptionPlan $plan, bool $notify = true): array
    {
        $result = ['locked' => [], 'unlocked' => []];
        if (! $plan) {
            return $result;
        }
        foreach (self::TYPES as $type) {
            $limit = self::limit($plan, $type);
            $rows = self::candidates($store, $type);
            foreach ($rows->values() as $i => $row) {
                $keep = $limit === -1 || $i < $limit;
                if ($keep && $row->plan_locked_at) {
                    self::unlock($row, $type);
                    $result['unlocked'][$type] = ($result['unlocked'][$type] ?? 0) + 1;
                } elseif (! $keep && ! $row->plan_locked_at) {
                    self::lock($row, $type);
                    $result['locked'][$type] = ($result['locked'][$type] ?? 0) + 1;
                }
            }
        }

        if ($result['locked'] || $result['unlocked']) {
            $store->load('owner');
            if ($result['locked'] && $result['locked'] !== [] && $notify) {
                $store->owner?->notify(new StoreActivityNotification(
                    'plan_items_locked',
                    'Some items were locked',
                    "{$store->name} is now on {$plan->name}, which allows fewer of these: ".self::describe($result['locked'])
                        .' Nothing was deleted. Upgrade, or choose what to keep in Branches, Staff and Services.',
                    '/dashboard/billing'
                ));
            }
            if ($result['unlocked'] && $notify) {
                $store->owner?->notify(new StoreActivityNotification(
                    'plan_items_unlocked',
                    'Locked items are back',
                    "{$plan->name} has room again, so these were unlocked: ".self::describe($result['unlocked']),
                    '/dashboard/billing'
                ));
            }
        }

        return $result;
    }

    /**
     * What a plan would lock right now, without changing anything — for the
     * downgrade warning. [type => n].
     */
    public static function preview(Store $store, SubscriptionPlan $plan): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $limit = self::limit($plan, $type);
            $count = self::candidates($store, $type)->count();
            if ($limit !== -1 && $count > $limit) {
                $out[$type] = $count - $limit;
            }
        }

        return $out;
    }

    /**
     * The owner unlocks a locked item. With room under the limit it simply
     * unlocks; at the limit, $swapWithId (an open item of the same type) is
     * locked in its place.
     */
    public static function swap(Store $store, string $type, int $unlockId, ?int $swapWithId, ?SubscriptionPlan $plan): array
    {
        $rows = self::candidates($store, $type);
        $target = $rows->firstWhere('id', $unlockId);
        if (! $target || ! $target->plan_locked_at) {
            throw ValidationException::withMessages(['item' => 'That item is not locked by your plan.']);
        }
        $limit = $plan ? self::limit($plan, $type) : 0;
        $open = $rows->whereNull('plan_locked_at');

        $swapped = null;
        if ($limit !== -1 && $open->count() >= $limit) {
            $swapped = $swapWithId ? $open->firstWhere('id', $swapWithId) : null;
            if (! $swapped) {
                [$one] = self::LABELS[$type];
                throw ValidationException::withMessages(['swap_with_id' => "Your plan is full. Pick an open {$one} to lock in its place."]);
            }
            self::lock($swapped, $type);
        }
        self::unlock($target, $type);

        return ['unlocked' => $target->fresh(), 'locked' => $swapped?->fresh()];
    }

    /** "2 branch shops, 5 staff accounts and 4 services." */
    public static function describe(array $counts): string
    {
        $parts = [];
        foreach ($counts as $type => $n) {
            [$one, $many] = self::LABELS[$type];
            $parts[] = $n.' '.($n === 1 ? $one : $many);
        }
        $last = array_pop($parts);

        return ($parts ? implode(', ', $parts).' and ' : '').$last.'.';
    }

    private static function lock(Model $row, string $type): void
    {
        $fields = ['plan_locked_at' => now()];
        if ($type === 'branch') {
            $fields['status'] = 'inactive';
        } elseif ($type === 'service') {
            $fields['is_active'] = false;
        }
        $row->forceFill($fields)->save();
        if ($type === 'service') {
            // A combo left with fewer than 2 orderable services is paused, same as deleting one.
            $row->pausePackagesLeftTooSmall();
        }
        self::forgetCaches($row, $type);
    }

    /** The staff list is cached per store (StaffController::index). */
    private static function forgetCaches(Model $row, string $type): void
    {
        if ($type === 'staff') {
            Cache::driver('file')->forget("store_staff_{$row->store_id}");
        }
    }

    private static function unlock(Model $row, string $type): void
    {
        $fields = ['plan_locked_at' => null];
        if ($type === 'branch') {
            $fields['status'] = 'active';
        } elseif ($type === 'service') {
            $fields['is_active'] = true;
        }
        $row->forceFill($fields)->save();
        self::forgetCaches($row, $type);
    }
}
