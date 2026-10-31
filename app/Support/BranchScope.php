<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Which branch a dashboard request may see.
 *
 * - A branch manager or staff account pinned to a branch always gets that
 *   branch, whatever branch_id it sends (they cannot switch branches).
 * - The store owner gets the branch they picked (?branch_id=), or null for
 *   "All Branches (Consolidated View)". A branch from another store is
 *   treated as no match (-1) rather than ignored, so it never widens the
 *   result to the whole store.
 */
class BranchScope
{
    /** @return int|null null = every branch of the store */
    public static function resolve(Request $request, Store $store): ?int
    {
        $user = $request->user();
        if ($user && ! $user->hasRole('store_owner') && $user->staffProfile?->store_branch_id) {
            return (int) $user->staffProfile->store_branch_id;
        }

        if (! $request->filled('branch_id')) {
            return null;
        }

        $branchId = (int) $request->input('branch_id');

        return $store->branches()->whereKey($branchId)->exists() ? $branchId : -1;
    }
}
