<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Support\PlanLimits;
use App\Support\SubscriptionSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Items locked by the shop's plan (App\Support\PlanLimits): the owner sees the
 * limits, swaps which ones stay open, and gets a preview of what a smaller
 * plan would lock before downgrading. Owner only.
 */
class PlanLockController extends Controller
{
    private function owns(Request $request, Store $store): bool
    {
        return $store->owner_id === $request->user()->id;
    }

    /** Per type: the plan's limit, how many are open and how many are locked. */
    public function index(Request $request, Store $store): JsonResponse
    {
        if (! $this->owns($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can manage plan limits.'], 403);
        }
        $plan = SubscriptionSwitcher::current($store)?->plan;
        $data = [];
        foreach (PlanLimits::TYPES as $type) {
            $rows = PlanLimits::candidates($store, $type);
            $data[$type] = [
                'limit' => $plan ? PlanLimits::limit($plan, $type) : 0,
                'open' => $rows->whereNull('plan_locked_at')->count(),
                'locked' => $rows->whereNotNull('plan_locked_at')->count(),
            ];
        }

        return response()->json(['success' => true, 'data' => ['plan' => $plan?->name, 'types' => $data]]);
    }

    /** What moving to this plan would lock today (the downgrade warning). */
    public function preview(Request $request, Store $store): JsonResponse
    {
        if (! $this->owns($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can see this.'], 403);
        }
        $plan = SubscriptionPlan::findOrFail($request->integer('plan_id'));

        return response()->json(['success' => true, 'data' => ['would_lock' => (object) PlanLimits::preview($store, $plan)]]);
    }

    /** Unlock a locked item; at the limit, swap_with_id (an open one of the same type) is locked instead. */
    public function unlock(Request $request, Store $store, string $type, int $id): JsonResponse
    {
        if (! $this->owns($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can choose what stays open.'], 403);
        }
        $request->merge(['type' => $type]);
        $validated = $request->validate([
            'type' => ['required', Rule::in(PlanLimits::TYPES)],
            'swap_with_id' => ['nullable', 'integer'],
        ]);
        $plan = SubscriptionSwitcher::current($store)?->plan;

        $result = DB::transaction(fn () => PlanLimits::swap($store, $type, $id, $validated['swap_with_id'] ?? null, $plan));
        if ($type === 'staff') {
            $result['unlocked']->loadMissing('user:id,name');
            $result['locked']?->loadMissing('user:id,name');
        }
        $name = fn ($row) => $type === 'staff' ? ($row?->user?->name ?? "#{$row?->id}") : ($row?->name ?? "#{$row?->id}");
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'plan_item_swapped',
            'model_type' => $result['unlocked']::class,
            'model_id' => $result['unlocked']->id,
            'payload' => ['type' => $type, 'unlocked' => $name($result['unlocked']), 'locked' => $result['locked'] ? $name($result['locked']) : null],
        ]);

        return response()->json([
            'success' => true,
            'message' => $result['locked']
                ? "{$name($result['unlocked'])} is open again; {$name($result['locked'])} is locked in its place."
                : "{$name($result['unlocked'])} is open again.",
        ]);
    }
}
